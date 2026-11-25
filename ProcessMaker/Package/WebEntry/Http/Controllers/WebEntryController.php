<?php

namespace ProcessMaker\Package\WebEntry\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use ProcessMaker\Events\RequestAction;
use ProcessMaker\Events\ScreenBuilderStarting;
use ProcessMaker\Facades\WorkflowManager;
use ProcessMaker\Http\Controllers\Controller;
use ProcessMaker\Managers\ScreenBuilderManager;
use ProcessMaker\Models\Process;
use ProcessMaker\Models\ProcessRequest;
use ProcessMaker\Models\Screen;
use ProcessMaker\Nayra\Storage\BpmnDocument;
use ProcessMaker\Providers\WorkflowServiceProvider;

class WebEntryController extends Controller
{
    /**
     * Render the web entry form for a process + start event node.
     * Canonical standard URL: /webentry/{process}/{node}
     */
    public function show(Request $request, $processId, $nodeId)
    {
        $process = Process::findOrFail($processId);
        [$event, $webEntry, $screenVersion, $definitions] = $this->resolve($process, $nodeId);

        return $this->renderForm($request, $process, $nodeId, $webEntry, $screenVersion);
    }

    /**
     * Handle a web entry form submission.
     * Canonical standard URL: /webentry/{process}/{node}
     */
    public function store(Request $request, $processId, $nodeId)
    {
        $process = Process::findOrFail($processId);
        [$event, $webEntry, $screenVersion, $definitions] = $this->resolve($process, $nodeId);

        return $this->startRequest($request, $process, $nodeId, $event, $webEntry);
    }

    /**
     * Render the web entry form for a single identifier that may be either a
     * process id or an existing request id (mapped to its process).
     * Convenience URL: /webentry/{id}
     */
    public function showById(Request $request, $id)
    {
        [$process, $nodeId] = $this->resolveByIdentifier($id);
        [$event, $webEntry, $screenVersion, $definitions] = $this->resolve($process, $nodeId);

        return $this->renderForm($request, $process, $nodeId, $webEntry, $screenVersion);
    }

    /**
     * Handle a web entry form submission for the convenience URL.
     */
    public function storeById(Request $request, $id)
    {
        [$process, $nodeId] = $this->resolveByIdentifier($id);
        [$event, $webEntry, $screenVersion, $definitions] = $this->resolve($process, $nodeId);

        return $this->startRequest($request, $process, $nodeId, $event, $webEntry);
    }

    /**
     * Resolve the process, start event node id and web_entry config for a given node.
     *
     * @return array{0: \ProcessMaker\Nayra\Contracts\BPMN\StartEventInterface, 1: array, 2: ?\ProcessMaker\Models\ScreenVersion, 3: mixed}
     */
    private function resolve(Process $process, string $nodeId): array
    {
        $definitions = $process->getDefinitions();

        $webEntry = null;
        foreach ($definitions->getElementsByTagNameNS(BpmnDocument::BPMN_MODEL, 'startEvent') as $startEvent) {
            if ($startEvent->getAttribute('id') !== $nodeId) {
                continue;
            }
            $config = $this->decodeConfig($startEvent);
            $webEntry = isset($config['web_entry']) && is_array($config['web_entry']) ? $config['web_entry'] : null;
            break;
        }

        if (empty($webEntry) || empty($webEntry['screen_id'])) {
            abort(404, __('Web entry is not configured for this start event.'));
        }

        $event = $definitions->getEvent($nodeId);
        if ($event === null) {
            abort(404, __('Start event not found.'));
        }

        $screen = Screen::find($webEntry['screen_id']);
        if ($screen === null) {
            abort(404, __('Screen not found.'));
        }

        // Prefer the published version; fall back to the screen itself.
        $screenVersion = $screen->versionFor() ?: $screen;

        return [$event, $webEntry, $screenVersion, $definitions];
    }

    /**
     * Given a process id or a request id, return [Process, nodeId].
     *
     * @return array{0: Process, 1: string}
     */
    private function resolveByIdentifier($id): array
    {
        $process = Process::find($id);
        if ($process !== null) {
            return [$process, $this->findWebEntryStartEvent($process)];
        }

        $request = ProcessRequest::find($id);
        if ($request !== null) {
            $process = $request->process ?? Process::find($request->process_id);
            if ($process !== null) {
                return [$process, $this->findWebEntryStartEvent($process)];
            }
        }

        abort(404);
    }

    /**
     * Find the first start event node id that has a configured web_entry screen.
     */
    private function findWebEntryStartEvent(Process $process): string
    {
        $definitions = $process->getDefinitions();
        foreach ($definitions->getElementsByTagNameNS(BpmnDocument::BPMN_MODEL, 'startEvent') as $startEvent) {
            $config = $this->decodeConfig($startEvent);
            if (!empty($config['web_entry']) && is_array($config['web_entry']) && !empty($config['web_entry']['screen_id'])) {
                return $startEvent->getAttribute('id');
            }
        }

        abort(404, __('No web entry start event found for this process.'));
    }

    /**
     * Decode the pm:config attribute of a start event.
     */
    private function decodeConfig($startEvent): array
    {
        $configJson = $startEvent->getAttributeNS(WorkflowServiceProvider::PROCESS_MAKER_NS, 'config');
        if (empty($configJson)) {
            return [];
        }
        // BPMN attributes are HTML-entity encoded (&#34; for quotes).
        $configJson = html_entity_decode($configJson, ENT_QUOTES | ENT_HTML401, 'UTF-8');
        $config = json_decode($configJson, true);

        if (!is_array($config)) {
            // Some BPMN exports omit trailing braces. Attempt a minimal repair
            // by balancing curly braces before giving up.
            $opens = substr_count($configJson, '{');
            $closes = substr_count($configJson, '}');
            if ($opens > $closes) {
                $config = json_decode($configJson . str_repeat('}', $opens - $closes), true);
                if (is_array($config)) {
                    Log::warning('WebEntry: repaired unbalanced pm:config JSON', [
                        'node' => $startEvent->getAttribute('id'),
                    ]);
                }
            }
        }

        return is_array($config) ? $config : [];
    }

    /**
     * Enforce password protection when enabled on the web_entry config.
     */
    private function assertPasswordAccess(Request $request, array $webEntry): void
    {
        $enabled = !empty($webEntry['enable_password_protect']);
        $password = $webEntry['password'] ?? null;

        if (!$enabled || $password === null || $password === '') {
            return;
        }

        $provided = $request->input('password', $request->cookie('webentry_password'));

        if (!is_string($provided) || !hash_equals((string) $password, $provided)) {
            abort(403, __('A valid password is required to access this form.'));
        }
    }

    /**
     * Build and return the form view.
     */
    private function renderForm(Request $request, Process $process, string $nodeId, array $webEntry, $screenVersion)
    {
        $this->assertPasswordAccess($request, $webEntry);

        $manager = app(ScreenBuilderManager::class);
        event(new ScreenBuilderStarting($manager, $screenVersion->type ?? 'FORM'));

        $submitUrl = route('package.webentry.store', [$process->id, $nodeId]);
        $completed = $request->query('completed');

        return view('webentry::webentry', [
            'process' => $process,
            'nodeId' => $nodeId,
            'screen' => $screenVersion,
            'manager' => $manager,
            'submitUrl' => $submitUrl,
            'completed' => $completed,
            'completedUrl' => $webEntry['completed_url'] ?? null,
        ]);
    }

    /**
     * Start a new request from the web entry start event and redirect.
     */
    private function startRequest(Request $request, Process $process, string $nodeId, $event, array $webEntry)
    {
        $this->assertPasswordAccess($request, $webEntry);

        $data = $request->input('data', $request->except(['password']));
        if (!is_array($data)) {
            $data = [];
        }

        try {
            $processRequest = WorkflowManager::triggerStartEvent($process, $event, $data);
        } catch (\Throwable $exception) {
            Log::error('WebEntry start failed', [
                'process_id' => $process->id,
                'node_id' => $nodeId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => __('Unable to start the process.') . ' ' . $exception->getMessage(),
            ], 422);
        }

        event(new RequestAction($processRequest, RequestAction::ACTION_CREATED));

        $redirect = $this->completedRedirect($process, $nodeId, $webEntry);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'id' => $processRequest->id,
                'redirect' => $redirect,
            ]);
        }

        return redirect()->away($redirect);
    }

    /**
     * Determine where to send the user after a successful submission.
     */
    private function completedRedirect(Process $process, string $nodeId, array $webEntry): string
    {
        $action = $webEntry['completed_action'] ?? null;

        if ($action === 'URL' && !empty($webEntry['completed_url'])) {
            return (string) $webEntry['completed_url'];
        }

        return route('package.webentry.show', [$process->id, $nodeId]) . '?completed=1';
    }
}