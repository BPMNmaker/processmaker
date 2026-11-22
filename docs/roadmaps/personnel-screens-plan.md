# Plan: Build Req1 / Req2 personnel screens in ProcessMaker 4

## 1. Context and decisions

- Sources: `requests/1/HRIS.xlsx` (15 sheets), `requests/2/فرم اطلاعات پرسنل.docx` (4 sections), `requests/2/note.txt` (13 documents, delivered as one ZIP), `requests/2/ساختار پوشه_بندی و بارگذاری مدارک پرسنلی/` (14 folders).
- Delivery: local `ProcessMaker/Package/WebEntry/` package + the single `config/app.php` provider line. **Skip composer / BPMNmaker packages** (composer auth is broken; `bpmnmaker/webentry` is a non-functional accessibility skeleton).
- Decisions:
  - **D0 — Core-touch constraint (user requirement):** the new work must not modify ProcessMaker core. See §8 for the audit. The new work only touches the custom `ProcessMaker/Package/WebEntry/` package and screen/process **data** (rows), not core code.
  - **D1 — Local package, no composer.**
  - **D2 — Req1 = two screens**: employee (sheets 1-7,12,13) + HR (sheets 8,9,10,11,14,15), per note.txt line 11.
  - **D3 — Entry types differ**: employees use the **anonymous webentry** (`web` middleware, no auth); HR uses an **authenticated PM4 task** (HR logs into PM4 and opens the screen from Inbox). The HR screen is wired with `pm:screenRef`, **not** another webentry URL.
  - **D4 — Req2 = anonymous webentry (employee) + HR review task** (user-confirmed): employee fills 4 sections + one ZIP; HR then reviews/approves via an authenticated PM4 task. Note.txt requires a single ZIP, so the primary upload is **one ZIP** (`personnel_docs`); 13 per-category uploads are an optional variant (§7).
  - **D5 — Anonymous uploads via a custom `POST /webentry/upload` endpoint** (chunk-aware) + `window.PM4ConfigOverrides.postFileEndpoint`. This covers Req1's photo (sheet 01) and Req2's ZIP without login. Fallback: native input + `FormData` (§7).
  - **D6 — Final media on MinIO (user-confirmed):** configure the existing `s3` disk (`config/filesystems.php:67`, env `AWS_*` + `AWS_USE_PATH_STYLE_ENDPOINT=true`) and set `MEDIA_DISK=s3` so Spatie MediaLibrary stores the photo/ZIP on MinIO. Temp upload staging stays on a local disk. Because core `FileController::download` (`FileController.php:302`) is hardcoded to the local `public` disk, add a **package-local** `GET webentry/files/{file}/contents` endpoint and point the HR review screen's `FileDownload` at it via `config.endpoint` (a standard read-only `FileUpload` shows the name but has no download button).

## 2. Screen map and BPMN wiring

| Screen | DB id | Entry | Audience | Pages | Source |
|---|---|---|---|---|---|
| Req1 employee | existing `1` | webentry start event → `web_entry.screen_id = 1` | anonymous | 9 pages: sheets 01-07,12,13 | HRIS.xlsx |
| Req1 HR | new (auto) | PM4 task → `pm:screenRef = <new id>` | authenticated HR | 6 pages: sheets 08,09,10,11,14,15 | HRIS.xlsx |
| Req2 personnel | existing `2` | webentry start event → `web_entry.screen_id = 2` | anonymous | 4 pages + ZIP upload | docx + note.txt |
| Req2 HR review | new (auto) | PM4 task → `pm:screenRef = <new id>` | authenticated HR | 2 pages: read-only summary + decision | docx + note.txt |

Screen config = array of pages: `[{ "name": "<page>", "items": [ { "label", "component", "config" } ] }]`.
References: `database/processes/screens/default-form-screen.json`, `tests/Feature/Templates/fixtures/basic-form-screen.json`, `tests/Fixtures/test_nested_record_list.json` (record list), `tests/Fixtures/sanitize_single_rich_text_inside_loop_screen.json` (loop).
BPMN wiring reference: `tests/Fixtures/single_task_with_screen.bpmn` (`pm:screenRef`), `tests/Feature/Api/processes/RegularStartAndWebEntry.bpmn` (`web_entry.screen_id`).

Publishing: `Screen` uses `HasVersioning` with no versionable-attribute override, so `save()` auto-creates a published `ScreenVersion`; WebEntry renders `$screen->versionFor()` (latest published). Load screens with `Screen::withoutGlobalScope('published')->find($id)` because the `published` global scope hides screens with no version.

## 3. Field-type mapping (from `node_modules/@processmaker/screen-builder/src/form-builder-controls.js`)

| Source | PM4 component | config notes (verified against `form-builder-controls.js`) |
|---|---|---|
| Short text | `FormInput` | `config:{icon,label,name,placeholder,validation,helper,type:"text",dataFormat:"string",readonly:false}` |
| Number (moadel, sabaqe) | `FormInput` | same, `dataFormat:"int"` or `"float"` |
| Jalali date | `FormInput` | same, `dataFormat:"string"`, placeholder `۱۴۰۳/۰۱/۰۱`, regex `^\d{4}/\d{2}/\d{2}$` (core picker is Gregorian) |
| Single choice (gender, marital, blood) | `FormSelectList` | `config.options:{showRenderAs:true,dataSource:"provideData",jsonData:"",pmqlQuery:"",dataName:"response",renderAs:"dropdown"\|"checkbox",allowMultiSelect:false,selectedOptions:[],optionsList:[{value,content}],optionsListExtra:[],key:"value",value:"content",ariaLabel:"ariaLabel",valueTypeReturned:"single"}` (`renderAs` is only `dropdown`/`checkbox`; `checkbox` = radio group when single) |
| Multi choice (housing, messenger) | `FormSelectList` | same, `allowMultiSelect:true`, `renderAs:"checkbox"` (or `"dropdown"` for multi-select), `valueTypeReturned:"single"` |
| Long text / address / notes | `FormTextArea` | `config:{icon,label,placeholder,helper,rows:2,richtext:false}` |
| Repeating rows (children, education, jobs, contacts) | `FormLoop` | `config:{icon:"fas fa-redo",name:"<name>",label:null,settings:{add:true,type:"new",times:"1",varname:"<name>"}}`, `config.name` = `config.settings.varname`, `container:true`, `items:[...field objects...]` (flat row template; types are `new`/`existing`) |
| Row table (alternative) | `FormRecordList` | `editable:true`, `config.form:"<companion page name>"`, `config.fields:{jsonData:"[]",dataSource:"provideData",optionsList:[{value,content}],editIndex:null,removeIndex:null,...}` |
| Photo / document upload | `FileUpload` | `accept`, `multipleUpload`; uploads via §5.5 endpoint. Standard list has delete, **no download** button |
| File download (read-only) | `FileDownload` | `config:{label,name,endpoint}`; `requestEndpoint()` (`file-download.vue:134`) = `${endpoint}/${file.id}/contents`. `endpoint` is not in the inspector, but `Json2Vue.js:204` binds **every** `config` key as a prop, so setting it in the generator's raw config JSON works |

Field `name` must be ASCII and match `^(?:[A-Za-z])(?:[0-9A-Z_.a-z])*(?<![.])$`; labels are Persian. **Names must be unique across the whole screen** (PM4 stores all fields in one request-data namespace); loop-internal fields are scoped per row but their `name` must still be unique within the row template.

### 3.1 Screen item template (for the generator)

Each page is `{"name":"<page>","items":[<item>,...]}`. A minimal item needs `label` + `component` + `config` for rendering; `inspector`/`editor-*` are optional (the builder regenerates them on first edit). Examples:

```json
{ "label": "نام", "component": "FormInput", "config": { "icon": "far fa-square", "name": "first_name", "type": "text", "label": "نام", "helper": null, "readonly": false, "dataFormat": "string", "validation": "required", "placeholder": "" } }
```
```json
{ "label": "جنسیت", "component": "FormSelectList", "config": { "icon": "fas fa-angle-double-down", "label": "جنسیت", "placeholder": "", "validation": "", "dataSourceUrl": "", "dataSourceEndpoint": "", "rootElement": "response", "options": { "showRenderAs": true, "dataSource": "provideData", "jsonData": "", "pmqlQuery": "", "dataName": "response", "renderAs": "dropdown", "allowMultiSelect": false, "selectedOptions": [], "optionsList": [ {"value":"female","content":"زن"}, {"value":"male","content":"مرد"} ], "optionsListExtra": [], "key": "value", "value": "content", "ariaLabel": "ariaLabel", "valueTypeReturned": "single" }, "helper": null } }
```
```json
{ "label": "فرزندان", "component": "FormLoop", "container": true, "config": { "icon": "fas fa-redo", "name": "children", "label": "فرزندان", "settings": { "add": true, "type": "new", "times": "1", "varname": "children" } }, "items": [ { "label": "نام", "component": "FormInput", "config": { "name": "child_name", "type": "text", "label": "نام", "dataFormat": "string", "validation": "required", "placeholder": "", "helper": null, "readonly": false, "icon": "far fa-square" } } ] }
```
```json
{ "label": "مدارک (ZIP)", "component": "FileUpload", "config": { "icon": "fas fa-file-upload", "label": "مدارک (ZIP)", "accept": "application/zip,.zip,.rar", "multipleUpload": false } }
```
```json
{ "label": "دانلود مدارک", "component": "FileDownload", "config": { "icon": "fas fa-file-download", "label": "دانلود مدارک", "name": "personnel_docs", "endpoint": "<url('/webentry/files')>" } }
```

## 4. Field inventory

### 4.1 Req1 employee — screen id 1 (pages 01-07,12,13)

- **01-اطلاعات هویتی**: `personnel_no`*, `first_name`*, `last_name`*, `father_name`, `national_id`*, `birth_certificate_no`, `birth_cert_place`, `birth_date`(Jalali), `birth_place`, `gender`(select: زن/مرد), `nationality`, `marital_status`(select: مجرد/متأهل), `religion`, `blood_type`(select), `photo`(`FileUpload`, accept `image/*`), `notes`
- **02-اطلاعات تماس و اقامت**: `personnel_no`, `mobile_primary`*, `mobile_alt`, `phone`, `personal_email`, `address`(textarea), `postal_code`, `housing_status`(multi select), `residence_duration`, `preferred_contact`(multi select), `notes`
- **03-اطلاعات تماس اضطراری**: loop `emergency_contacts`: `name`, `relation`, `phone`, `address`
- **04-اطلاعات خانوادگی**: spouse `spouse_name`,`spouse_national_id`,`spouse_birth_date`,`spouse_job`,`spouse_phone`; loop `children`: `name`,`national_id`,`birth_date`,`education_status`,`marital_status`; loop `dependents`: `name`,`relation`,`birth_date`,`address`,`phone`
- **05-سوابق تحصیلی**: loop `education`: `degree`,`field`,`university`,`study_years`,`gpa`,`verification_status`,`document_no`
- **06-سوابق شغلی و بیمه‌ای**: loop `work_history`: `employer`,`job_title`,`unit`,`start_date`,`end_date`,`last_salary`,`termination_reason`,`insurance_history`,`relevant_years`
- **07-اطلاعات استخدامی**: `hire_date`,`employment_type`(select),`employment_status`(select),`org_unit`,`job_title`,`job_code`,`org_position`,`manager`,`work_location`,`cost_center`,`shift`,`org_email`,`internal_phone`
- **12-نظام وظیفه**: `military_status`(select: خدمت کرده/کفالت/معاف/پزشکی),`exemption_type`,`document_no_date`,`document_review_status`
- **13-مهارت‌ها، زبان و گواهینامه‌ها**: `foreign_language`,`language_level`,`software`,`specialized_skills`,`professional_certificates`,`safety_competency`

### 4.2 Req1 HR — new screen (pages 08,09,10,11,14,15)

- **Context (read-only)**: `personnel_no`, `first_name`, `last_name`, `national_id` (from the employee's submission, `config.readonly:true`) at the top of the screen so HR can identify the employee.
- **08-قرارداد و احکام**: `contract_type`,`contract_no`,`contract_date`,`contract_start`,`contract_end`,`probation_period`,`last_decree`,`contract_changes`
- **09-حقوق و مزایا**: `base_salary`,`seniority`,`job_allowance`,`recurring_benefits`,`non_recurring_benefits`,`overtime`,`deductions`,`net_paid`,`salary_changes`
- **10-اطلاعات بانکی و مالی**: `bank_name`,`branch`,`account_no`,`iban`,`account_holder`,`account_verification`
- **11-بیمه و مالیات**: `insurance_no`,`insurance_branch`,`insurance_start`,`supplementary_insurance`,`insurer`,`tax_info`
- **14-آموزش، عملکرد و توسعه**: `training_history`,`training_needs`,`performance_review`,`competencies`,`development_plan`,`promotion_history`,`succession_status`
- **15-تجهیزات، ایمنی و مدارک پرونده**: `equipment`,`system_access`,`hse_training`,`occupational_health`,`file_documents`,`last_updated`,`data_verification`

### 4.3 Req2 personnel — screen id 2

- **Section 1 اطلاعات فردی و هویتی**: `first_name`*,`last_name`*,`father_name`,`national_id`*,`birth_certificate_no`,`birth_cert_place`,`birth_date`(Jalali),`birth_place`,`gender`(select),`marital_status`(select),`religion`,`sect`,`blood_type`,`military_status`(select),`exemption_reason`,`address`(textarea),`housing_status`(select),`mobile_primary`*,`emergency_contact_phone`,`emergency_contact_name`,`mobile_alt`,`home_phone`,`postal_code`,`personal_email`,`preferred_messenger`(multi select),`messenger_number`
- **Section 2 اطلاعات همسر**: `spouse_name`,`spouse_national_id`,`spouse_birth_date`,`spouse_job`,`spouse_workplace`,`spouse_phone`
- **Section 3 اطلاعات فرزندان**: loop `children`: `name`,`birth_date`,`education_status`,`marital_status`,`mobile`
- **Section 4 سایر افراد تحت تکفل**: loop `dependents`: `name`,`relation`,`birth_date`,`address`,`mobile`
- **Upload**: `personnel_docs` (`FileUpload`, accept `application/zip,.zip,.rar`), one ZIP per note.txt.

### 4.4 Req2 HR review — new screen (HR task)

- **Page «مروری بر اطلاعات»**: read-only mirrors of the employee's key fields (`first_name`, `last_name`, `national_id`, `mobile_primary`, `address`, …) with `config.readonly:true`; `personnel_docs` via a **`FileDownload`** control (not a read-only `FileUpload`, which shows the name but has no download button) with `config.endpoint` set to the package download base (§5.5) so HR can download the ZIP from MinIO.
- **Page «تصمیم HR»**: `hr_decision` (`FormSelectList`: تأیید/رد), `hr_comments` (`FormTextArea`), and a `FormButton` (submit) to complete the task.
- Wired to the Req2 HR task (`pm:screenRef`); HR sees the request data + ZIP, then records the decision.

## 5. Implementation tasks (ordered)

0. **Discover real IDs and configure MinIO** (do this first; the summary's 138/139 are task ids, not process ids):
   - **MinIO (D6)**: set in `.env` `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT=<minio-url>`, `AWS_USE_PATH_STYLE_ENDPOINT=true`, `MEDIA_DISK=s3`; then `php artisan config:clear`. The `s3` disk already exists (`config/filesystems.php:67`) and `media-library.php:9` reads `MEDIA_DISK`. Verify `Storage::disk('s3')->put('probe.txt','ok')` before relying on it.
   - `php artisan tinker --execute="echo \ProcessMaker\Models\Process::all(['id','name'])->toJson();"` and `echo \ProcessMaker\Models\Screen::withoutGlobalScope('published')->all(['id','title'])->toJson();`
   - Identify the Req1 process (the one whose start event has `web_entry.screen_id=1`) and Req2 process (`screen_id=2`). Record their `id`s for tasks 3-4.
   - **Create the HR group** (user-confirmed; `ProcessMaker\Models\Group` fillable = `name`,`description`,`manager_id`,`status`, `status` ∈ `ACTIVE|INACTIVE`): `php artisan tinker --execute="echo \ProcessMaker\Models\Group::create(['name'=>'HR','description'=>'HR reviewers','status'=>'ACTIVE'])->id;"` (or Admin > Groups UI). Attach the HR users via `GroupMember` (`group_id`,`member_id`,`member_type` = `ProcessMaker\Models\User::class`). Record `<hrGroupId>` for task 4.
1. **Generator command** — add `ProcessMaker/Package/WebEntry/Console/Commands/ImportPersonnelScreens.php`:
   - Compact field specs (`label`, `name`, `component`, `config`, `validation`, `options`) + `buildConfig()` that emits pages/items JSON.
   - Reuse config defaults from `form-builder-controls.js`.
   - Register in `WebEntryServiceProvider::boot()`: `$this->commands([ImportPersonnelScreens::class]);`.
2. **Save screens**:
   - Req1: `$s = Screen::withoutGlobalScope('published')->find(1); $s->config = $config; $s->save();`
   - Req2: same with `find(2)`.
   - Req1 HR: `Screen::create(['title'=>'...','description'=>'...','type'=>'FORM','config'=>$config,'screen_category_id'=>...]);` capture new id.
   - Req2 HR review: `Screen::create([...])` with the §4.4 config; capture new id.
   - Each `save()` publishes a `ScreenVersion`; verify `versionFor()` returns it.
3. **Wire webentry start events** (Req1 employee, Req2):
   - Get the start event id: `$events = $process->getStartEvents();` each entry has `id`, `name`, `config` (JSON string). Find the entry whose `config.web_entry.screen_id` is 1 / 2.
   - Edit `$process->bpmn` XML: find `<bpmn:startEvent id="<that id>">` and set its `pm:config` attribute so `web_entry.screen_id` = 1 / 2 and `require_valid_session:false`; set `completed_action` (`SCREEN`/`URL`) as desired for the post-submit page.
   - Ensure each webentry screen's `key` attribute is a JSON string containing `{"node_id":"<start event id>"}` (the controller's `store()` does `json_decode($screen->key, true)` and `getStartEvent($config['node_id'])`). The existing screens already have `key`; verify it matches the edited start event id.
   - To apply: set `$process->bpmn = $updatedBpmnXml; $process->save();`. `Process` uses `HasVersioning` (no versionable-attribute override) and `bpmn` is not guarded, so `save()` auto-publishes a new `ProcessVersion` (no explicit publish call). Then `php artisan optimize:clear`.
   - Note: `previewscreennextvite` loads `loaderScreenPreview.js` → `setupMain()`, which defines `window.ProcessMaker.apiClient` and the CSRF interceptor, so the FileUpload component and the custom `/webentry/upload` route (under `web` middleware + CSRF) work without extra auth setup.
4. **Wire HR tasks (Req1 + Req2)**:
   - For each process (Req1, Req2), inspect `$process->bpmn`. If an HR task exists, set its `pm:screenRef` (Req1 → Req1 HR screen id; Req2 → Req2 HR review screen id). If not, insert one:
     - (a) find the `<bpmn:sequenceFlow>` whose `sourceRef` is the webentry start event (its `targetRef` is the end event id);
     - (b) change that flow's `targetRef` to the new task id (`node_hr`);
     - (c) add `<bpmn:task id="node_hr" name="..." pm:screenRef="<HR screen id>" pm:assignment="group" pm:assignedGroups="<hrGroupId>">` with `<bpmn:incoming>` = the original flow id and `<bpmn:outgoing>` = a new flow id;
     - (d) add `<bpmn:sequenceFlow id="node_hr_out" sourceRef="node_hr" targetRef="<end event id>">`;
     - (e) add `<bpmn:incoming>node_hr_out</bpmn:incoming>` to the end event.
     - Update the `BPMNDIagram` shapes only for designer display (the engine ignores the diagram).
   - Apply with `$process->bpmn = $updatedBpmnXml; $process->save();` for each process (auto-publishes `ProcessVersion`).
   - Assign both tasks to the HR group: `pm:assignment="group" pm:assignedGroups="<hrGroupId>"`. HR opens the task from the authenticated PM4 Inbox; no webentry URL for HR.
5. **Anonymous upload endpoint** (Req1 photo + Req2 ZIP):
   - Add `Route::post('webentry/upload', [WebEntryController::class, 'upload'])->middleware('throttle:60,1')->name('package.webentry.upload');` to `ProcessMaker/Package/WebEntry/routes/web.php` (already `web` middleware + CSRF, public). Tune the throttle for chunk uploads (each chunk is one request).
   - Add a small `webentry_uploads` table (migration in the package) and load it with `$this->loadMigrationsFrom(__DIR__.'/database/migrations');` in `WebEntryServiceProvider::boot()`: columns `id`, `uuid`, `original_name`, `mime`, `path`, `data_name`, `created_at`, `updated_at`.
   - Controller mirrors `ProcessRequestFileController::store`/`chunk`/`saveUploadedFile`. Capture `$data_name = $laravelRequest->input('data_name', $file->getClientOriginalName())` (the `FileUpload` sends `query.data_name = prefix + name + row_id`, so for our fields it is the field name, e.g. `personnel_docs`) and store it in the row. If `chunk` param, `receive()`; if `$save->isFinished()` store the assembled file, else return `response()->json(['done' => $save->handler()->getPercentageDone()])` (required for the uploader's progress). If no `chunk`, store `$request->file('file')`. **Temp staging** writes to the local `tmp` disk (`config/filesystems.php:112`, already used by package-webentry). **Security**: enforce `config('media-library.max_file_size')` (default 256MB), validate `mime` against an allowlist (`image/jpeg,png`, `application/pdf`, `application/zip`, etc.), reject empty/invalid files, and store under a random `uuid` filename (never the client filename). **Response shape (verified against `file-upload.vue:641`)**: return `{ message: 'uploaded', fileUploadId: 'webentry_'.$row->uuid }` — the client's `fileUploaded` reads `msg.fileUploadId`; the `webentry_` prefix matches `file-download.vue:124` (`isWebEntryTemporaryFileId`) so the temp id is not treated as a real media id. The `id` key is only used when a `collection-id` meta exists, which webentry does not set.
   - Add a scheduled cleanup (e.g., `php artisan webentry:clean-uploads` registered in the package, run via the scheduler) that deletes `webentry_uploads` rows and their files older than N hours (e.g., 24h) to avoid orphaned anonymous uploads. **Register the schedule inside the package** (no core `app/Console/Kernel.php` edit): in `WebEntryServiceProvider::boot()` use `$this->app->booted(fn () => $this->app->make(\Illuminate\Console\Scheduling\Schedule::class)->command('webentry:clean-uploads')->hourly());`.
   - In `webentry.blade.php` (guard the global first): `window.PM4ConfigOverrides = window.PM4ConfigOverrides || {}; window.PM4ConfigOverrides.postFileEndpoint = @json(route('package.webentry.upload'));`.
   - On `store`: discover upload field names from the screen config (`items` with `component === 'FileUpload'` → `config.name`; for Req1 `photo`, for Req2 `personnel_docs`) or hardcode them, then walk `$data` and reattach. **Data shapes (verified)**: single upload → the value is the temp id directly (`$data[$key]` = `webentry_<uuid>`); multi upload → `$data[$key]` = `[{file:webentry_<uuid>}, ...]`. For each temp id, strip the `webentry_` prefix and find the `webentry_uploads` row by `uuid` (if missing/expired, return a 422 validation error for that field), then `$media = $processRequest->addMedia(Storage::disk('tmp')->path($row->path))->usingFileName($row->original_name)->withCustomProperties(['data_name' => $row->data_name])->toMediaCollection()` (`path` is relative to the `tmp` disk; `addMedia` needs an absolute path; **final media is written to MinIO because `MEDIA_DISK=s3`**). **`data_name` is mandatory**: `ProcessRequest::requestFiles()` (`ProcessRequest.php:986`) keys each file by `getCustomProperty('data_name')`, and the HR task's `FileUpload`/`FileDownload` reads `PM4ConfigOverrides.requestFiles[<field>]` (populated by `tasks/edit.blade.php:427`); without it the ZIP is attached but invisible/unlinked on the HR review screen. Replace the value with `$media->id` (single) or `[{file:$media->id}]` (multi), then delete the temp row/file. Persist with `$processRequest->data = $data; $processRequest->save();` (`data` is an `array` cast; the data store is rebuilt from it on load).
   - **Package-local download endpoint (D6, MinIO)**: add `Route::get('webentry/files/{file}/contents', [WebEntryController::class, 'download'])->name('package.webentry.file');` to the package `routes/web.php`. The core `FileController::download` is hardcoded to local `public` (`FileController.php:302`), so this endpoint streams from the media disk instead: `$media = Media::findOrFail($file); abort_unless($media->model_type === ProcessRequest::class, 403); return response()->stream(function () use ($media) { $stream = Storage::disk($media->disk)->readStream($media->getPath()); fpassthru($stream); fclose($stream); }, 200, ['Content-Type' => $media->mime_type, 'Content-Disposition' => 'attachment; filename="'.$media->file_name.'"']);`. **Authorization**: only allow files attached to a `ProcessRequest` the user can view (HR is authenticated; for anonymous webentry, only allow if the request is public — otherwise 403). Set the HR review `FileDownload` item's `config.endpoint = @json(url('/webentry/files'))` so `requestEndpoint()` builds `${endpoint}/${id}/contents` (`file-download.vue:148-150`).
6. **RTL / Persian**: set `custom_css` per screen (e.g., `.container{direction:rtl}`) or add `dir="rtl"` to the webentry container; verify against the active theme.
7. **Clear caches**: `php artisan optimize:clear` / `config:clear` after provider/command changes.

## 6. Validation

- Load Req1 employee and Req2 webentry URLs; confirm all pages/fields render, selects show options, loops add rows.
- Submit Req1 employee: `process_requests` row created; `data` contains all values; the HR task appears in the HR user's Inbox; open it, confirm the HR screen renders and submits; request completes.
- Submit Req2 employee: request created; `data` populated; ZIP media row exists **on MinIO (`MEDIA_DISK=s3`)** and is downloadable; the Req2 HR task appears in Inbox; open it, confirm the read-only summary and that the `FileDownload` shows the ZIP (this proves `data_name` was set, since `requestFiles['personnel_docs']` is what the component reads) and that download hits `GET /webentry/files/{id}/contents` (streams from MinIO); submit the decision; request completes.
- Verify the upload response contract: the browser network tab shows the upload POST returning `fileUploadId: 'webentry_<uuid>'` (not `id`), and the HR task's `FileDownload` shows the ZIP.
- Confirm `screen_versions` has a published row per screen and WebEntry `versionFor()` returns it.
- Re-run any screen tests (e.g., `php artisan test --filter=Screen`) if the suite is available.

## 7. Risks and open items

- **Verified against code** (no action needed): `FormLoop` renders inline from `config.settings.times` in webentry/preview; `Screen::versionFor()` with no process request returns `getLatestVersion()` (published `ScreenVersion`); `WorkflowManagerDefault::triggerStartEvent` → `validateData` uses empty rules by default, so arbitrary form data is accepted and persisted; `previewscreennextvite` → `setupMain()` defines `window.ProcessMaker.apiClient` + CSRF interceptor; `Process` uses `HasVersioning` with `bpmn` fillable, so `$process->bpmn=$xml; $process->save();` auto-publishes a `ProcessVersion`; `Spatie\MediaLibrary::addMedia(string|UploadedFile)` accepts a path; component configs match `form-builder-controls.js`; `vue-simple-uploader` sends `X-CSRF-TOKEN`/`X-Requested-With` and `chunk=true`, so the custom endpoint under `web` middleware + `FileReceiver` works; `Json2Vue.js:204` binds every `config` key as a prop, so `FileDownload.config.endpoint` reaches the `endpoint` prop.
- **Anonymous uploads**: core `POST /api/1.0/files` is auth-gated and needs an existing request id; `vue-form-renderer` FileUpload gets a `null` target without `meta[name=request-id]`. The custom endpoint (§5.5) is the primary fix; fallback is native `<input type=file>` + `FormData` submit + controller attach (no chunk endpoint, simpler, but upload fields are outside the screen builder). **Two verified contract requirements** (both would silently break the HR review screen): (1) the upload response must be `{ message, fileUploadId: 'webentry_<uuid>' }` — `file-upload.vue:641` reads `msg.fileUploadId`, not `msg.id` (the `id` key is only for collection screens); (2) the reattachment must set the `data_name` custom property via `->withCustomProperties(['data_name'=>$field])` — `ProcessRequest::requestFiles()` keys by `getCustomProperty('data_name')` and the HR task's `FileDownload` reads `PM4ConfigOverrides.requestFiles[<field>]` (populated by `tasks/edit.blade.php:427` from `requestFiles()`). **Endpoint resolution**: `$dataProvider.download()` uses the `/api`-based apiClient, so set the `FileDownload.config.endpoint` to an absolute `url('/webentry/files')` and verify the browser requests `/webentry/files/{id}/contents` (not `/api/webentry/...`).
- **MinIO / S3 (D6, user-confirmed)**: not configured in `.env` (no `AWS_*`/`MEDIA_DISK`; `MEDIA_DISK` defaults to local `public`). Add the env vars in task 0 and set `MEDIA_DISK=s3`. **Download caveat**: core `FileController::download` (`FileController.php:302`) is hardcoded to `Storage::disk('public')`, so with media on MinIO the standard `GET /files/{id}/contents` returns nothing. The package-local `GET /webentry/files/{file}/contents` (§5.5) is required; set the HR `FileDownload` `config.endpoint` to it. Without that endpoint, HR cannot download the ZIP.
- **HR task wiring**: if the Req1 or Req2 process has no HR task, adding one is a process change (BPMN + new `ProcessVersion`). A new HR group is created in task 0 (user-confirmed) and both tasks use `pm:assignment="group" pm:assignedGroups="<hrGroupId>"`.
- **Jalali dates**: core `FormDatePicker` is Gregorian; use text inputs with Jalali placeholders/regex.
- **RTL**: verify `dir="rtl"` placement with the theme.
- **13 per-category uploads (optional)**: repeat §5.5 per `FileUpload` item named after folders 1,3,4,5,6,7,8,9,10,11,12,13,14; set `accept` and optional `validation:"required"`. Higher payload/complexity than one ZIP.
- **Req2 HR review**: included (user-confirmed); screen in §4.4, task wired in §5 task 4.
- **Config size**: ~150 fields across 15 pages; the generator keeps it maintainable.

## 8. Core-touch audit (constraint: don't touch core)

Audited the actual working tree (`git status` / `git diff`). Only two non-package files are modified, both are **pre-existing prerequisites**, not part of the new screen/upload work:

| File | Change | Why it is required | Avoidable? |
|---|---|---|---|
| `config/app.php` | +1 line: `ProcessMaker\Package\WebEntry\WebEntryServiceProvider::class` in `providers` | Registers the custom WebEntry package (it is a local directory, not composer-installed, so Laravel auto-discovery does not apply) | No, unless the package is installed via composer (blocked by GitHub auth). One-line config change is the minimal alternative. |
| `ProcessMaker/Models/Process.php` | −2 lines: two extra closing braces in `manageCustomRoutes()` | **Syntax repair.** HEAD has 2 unbalanced `}` after the `catch` block; without removing them the file fails to parse and the whole app breaks. Verified with `git diff -U40`. | No. HEAD is syntactically invalid; the repair is mandatory. If you believe HEAD is valid, run `php -l` on the HEAD blob before reverting. |

**The new work does NOT touch core:**
- Screens → `Screen` / `ScreenVersion` **rows** (data), not code.
- BPMN edits → `Process` / `ProcessVersion` **rows** (data), not code.
- Upload endpoint, route, blade, migration, artisan command → all inside `ProcessMaker/Package/WebEntry/` (custom package).
- MinIO → `.env` only (`AWS_*`, `MEDIA_DISK=s3`); the `s3` disk already exists (`config/filesystems.php:67`) and `media-library.php:9` already reads `MEDIA_DISK`, so **no core file edit**. The package-local download endpoint is inside the package.
- No core controller/model/route/view/migration is edited.

**What is considered "core" vs "package":**
- Core = `ProcessMaker/` tree minus `ProcessMaker/Package/WebEntry/`, plus `routes/`, `resources/`, `database/migrations/`.
- `ProcessMaker/Package/WebEntry/` is the custom local package designated by the user (D1) and is the only code area the new work modifies.

**Untracked artifacts** (`db/`, `pm4.har`, `processmaker-several-version/`, `requests/`, `scripts/`, `database/migrations/2018_08_08_100000_create_telescope_entries_table.php`) are pre-existing/unrelated and not touched by this plan.