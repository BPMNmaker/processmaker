<?php

use Illuminate\Support\Facades\Route;
use ProcessMaker\Package\WebEntry\Http\Controllers\WebEntryController;

/*
|--------------------------------------------------------------------------
| Web Entry routes (public / anonymous)
|--------------------------------------------------------------------------
|
| Canonical standard web entry URL: /webentry/{process}/{node}
| Convenience URL (process id or request id): /webentry/{id}
|
| The start event must have a configured `web_entry.screen_id` and
| `web_entry.require_valid_session: false` (default) to be reachable here.
|
*/

Route::get('webentry/{processId}/{nodeId}', [WebEntryController::class, 'show'])
    ->name('package.webentry.show');

Route::post('webentry/{processId}/{nodeId}', [WebEntryController::class, 'store'])
    ->name('package.webentry.store');

Route::get('webentry/{id}', [WebEntryController::class, 'showById'])
    ->name('package.webentry.show.byid');

Route::post('webentry/{id}', [WebEntryController::class, 'storeById'])
    ->name('package.webentry.store.byid');