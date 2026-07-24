# Change Log
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

## 2.5.0 – 2026-07-24

Adds two new dashboard widgets that round out the "what's happening in the CRM" surface for users who want more than their personal workload on the Nextcloud home dashboard.

**SuiteCRM Activities** is a cross-module recent-activity feed covering the four canonical SuiteCRM activity types: Calls, Meetings, Tasks, and Notes. Rows are keyed on `date_modified`, so a Call rescheduled today surfaces above a Meeting held last week even if the Meeting was created more recently. The widget is tenant-wide within the caller's SuiteCRM ACL, not filtered to `assigned_user_id` — reads as "what has been touched in the CRM lately, that I have access to see". Complements the existing personal workload widgets (Cases, Tasks, pipeline) rather than duplicating them.

**SuiteCRM Contacts** lists the most recently added Contacts visible to the current user. Simple single-module query, sort by `date_entered DESC`, capped at 20 rows. Answers "who's new in the CRM" as a discovery aid rather than a workload cue.

Both widgets follow the same pattern established in 2.2.0 for Cases/Tasks/pipeline: `IWidget` + `IAPIWidget` + `IAPIWidgetV2` + `IIconWidget` (so they render identically on the classic dashboard and the NC 30+ server-side API dashboard), 120-second polling with a tab-hidden pause, connect-button empty state, hidden by default in the widget picker until the user enables them. No new configuration required; the SuiteCRM ACL layer already enforces per-user access.

### Added

- **`My SuiteCRM Activities` dashboard widget** (`SuiteCRMActivitiesWidget`, order 60): renders below the personal workload cluster (Cases/Tasks/pipeline at 30/40/50). Fan-out to the four canonical `ACTIVITY_MODULES` (Meetings, Calls, Tasks, Notes) filtered by `date_modified > now - 30 days`, merged client-side and sorted newest-first. Subline shape: `type · assigned user · relative-modified-time`. Four HTTP round-trips per poll, comfortably under SuiteCRM 8's default rate limits at the 120s poll cadence.
- **`SuiteCRMAPIService::getRecentActivities(url, token, userId, limit=20, lookbackDays=30)`**: single fan-out method the widget calls. Rows without `date_modified` are dropped rather than surfaced with an epoch-zero timestamp that would render as "56 years ago". Error envelope from any of the four upstream calls bubbles up unchanged so the controller returns 401 to the frontend, matching the existing widget error-handling contract.
- **`GET /apps/njordium_suitecrm/recent-activities`**: controller endpoint. Same 400-when-no-token / 401-on-upstream-error / 200-on-ok shape as `/upcoming` and `/my-cases`.
- **`src/views/Activities.vue` + `src/activities.js`**: Vue mount for the classic dashboard path. Same 120s polling loop with tab-visibility pause that the other widgets use. Type-aware DetailView deep links (each row opens the record in its correct SuiteCRM module).

- **`SuiteCRM Contacts` dashboard widget** (`SuiteCRMContactsWidget`, order 70): sits below Activities so the "discovery" cluster follows the "what's happening" cluster in visual order. Falls back to email as the row title when a Contact has no name captured yet, so an email-only capture from a lead-generation form still surfaces something clickable.
- **`SuiteCRMAPIService::getRecentContacts(url, token, userId, limit=20, lookbackDays=90)`**: single-module query with a 90-day lookback (wider than Activities to catch quieter tenants). Same tag/timestamp pattern (`type='contact'`, `entered_ts`) as the other service methods so the frontend layer stays uniform.
- **`GET /apps/njordium_suitecrm/recent-contacts`**: controller endpoint.
- **`src/views/RecentContacts.vue` + `src/recentContacts.js`**: Vue mount.

### Added (tests)

- **10 PHPUnit tests for the new service methods** on `SuiteCRMAPIServiceTest`: fan-out invariant (four modules, in `ACTIVITY_MODULES` order), sort-by-date-modified-descending, limit respected, upstream error envelope propagation, missing-timestamp rows dropped — mirrored for Activities and Contacts. Together they lock down the widget contract so the fan-out module list and the sort semantics cannot regress silently on a future refactor.

## 2.4.0 – 2026-07-23

Adds a per-user opt-out that lets the Calendar widget stop rendering SuiteCRM Tasks alongside Meetings and Calls. Rationale: reps who use the standalone "My open Tasks" widget introduced in 2.2.0 would otherwise see a dated Task twice on the dashboard, once in the schedule frame and once in the workload frame. The new toggle hands ownership of Tasks over to the workload widget for the users who want that split, while leaving 2.3.x behaviour unchanged for anyone who has never touched the setting.

The shutoff sits inside `SuiteCRMAPIService::getUpcoming()` so both the Vue-mounted classic dashboard path and the NC 30+ server-side dashboard API path honour it uniformly, without duplicated wiring at the controller and widget layers.

### Added

- **`calendar_show_tasks` personal preference** (`Settings\Personal`, `ConfigController::USER_ALLOWED_KEYS`): stored as `'1'`/`'0'` in `oc_preferences`. Default `'1'` on missing row so existing installs keep current behaviour without a migration. Bounded write path: only whitelisted keys reach `setUserValue`.
- **Toggle in Personal Settings** (`src/components/PersonalSettings.vue`): "Include Tasks in the Calendar widget" checkbox under Dashboard widget preferences, above the pipeline mode selector. Copy explains the intended split when the standalone Tasks widget is in play.

### Changed

- **`SuiteCRMAPIService::getUpcoming()`** now reads `calendar_show_tasks` from `IConfig::getUserValue` (default `'1'`) and skips the Tasks slice of `UPCOMING_MODULES` when the pref is `'0'`. One fewer HTTP round-trip to SuiteCRM per widget poll for opted-out users. Sort/overdue logic for the remaining Meetings and Calls is unchanged.
- **`package.json` version bumped from stale `1.2.0` to `2.4.0`** to match `appinfo/info.xml`. The two had been drifting silently since the 2.0.x rename because nothing at runtime reads `package.json` version, so the mismatch went unnoticed for four minor releases.

### Added (housekeeping)

- **`scripts/verify-version.js` + `prebuild`/`predev` npm hooks**: dependency-free check that reads `<version>` from `appinfo/info.xml` and compares it to `package.json` version. `npm run build` and `npm run dev` now fail fast if the two drift, closing the gap that let `package.json` sit at `1.2.0` through four minor releases. Also available as a standalone `npm run verify:version` for use in release checklists.
- **Three PHPUnit tests for the `calendar_show_tasks` preference** (`SuiteCRMAPIServiceTest`): unset pref (Tasks included by default), explicit `'1'` (Tasks included), explicit `'0'` (Tasks module never fetched + Meetings still delivered). The unset-pref test pins down the mock-default regression that failed the first two v2.4.0 release runs at PHPUnit — a `=== '1'` check collapsed to false against `IConfig::getUserValue`'s null default and silently dropped Tasks from every getUpcoming test. Read semantics changed to `!== '0'` so a null default now reads as "toggle unset -> include".

## 2.3.2 – 2026-07-22

Adds a per-user opt-out for the global Quick Actions floating button introduced in 2.3.0, and along the way fixes a live regression on the existing Personal Settings toggles.

Users who prefer to reach the write actions from Personal Settings only (or via the keyboard shortcut, or not at all) can now hide the FAB without disabling the underlying feature. The shutoff sits at the server-side listener rather than at the mounted button, so opted-out users pay zero JS cost per page render.

The 2.3.1 tag was skipped: while wiring the new checkbox we noticed that all three `NcCheckboxRadioSwitch` toggles on Personal Settings (search, notification, and the new FAB opt-out) rendered their initial state correctly but did not respond to clicks. Root cause was an `@update:checked` binding on a component that has since standardised on Vue 3's `update:modelValue` event and dropped the legacy one, so the handler simply never fired. Same class of two-way-binding regression we hit on the pipeline mode selector in 2.3.0. Rolled the fix into this release rather than shipping a broken 2.3.1 and a follow-up hotfix.

### Added

- **`quick_actions_enabled` personal preference** (`Settings\Personal`, `ConfigController::USER_ALLOWED_KEYS`): stored as `'1'`/`'0'` in `oc_preferences`. Default `'1'` on missing row so existing installs keep current behaviour without a migration. Bounded write path: only whitelisted keys reach `setUserValue`, so an authenticated user cannot write arbitrary rows via the setting endpoint.
- **Toggle in Personal Settings** (`src/components/PersonalSettings.vue`): "Show the floating Quick Actions button on every page" checkbox under the Quick actions section, separated by a top border so it reads as a display preference for the buttons above rather than yet another workflow choice. Changes take effect on the next page reload since the server-side listener decides at render time whether to inject the script.

### Changed

- **`Listener\AddQuickActionsScriptListener`** now reads `quick_actions_enabled` from `IConfig::getUserValue` before calling `Util::addScript`. Missing row defaults to `'1'`, so the listener behaviour is unchanged for anyone who has never touched the toggle. When set to `'0'` the listener returns early, saving one `<script>` tag and the FAB mount cost on every page render.

### Fixed

- **Personal Settings toggles now respond to clicks** (`src/components/PersonalSettings.vue`): swapped `@update:checked` to `@update:modelValue` on all three `NcCheckboxRadioSwitch` bindings (search, notification, and the new FAB opt-out). No behavioural or persistence change beyond restoring click responsiveness. First-time save of `search_enabled`, `notification_enabled`, and `quick_actions_enabled` now writes to `oc_preferences` as intended.

## 2.3.0 – 2026-07-22

Adds a **global Quick Actions floating button** so reps can log a Talk conversation, link a Deck card, or convert an email to a Case from anywhere in Nextcloud, with no need to navigate to Personal Settings first. A keyboard shortcut (`Cmd/Ctrl+Shift+K`) opens the menu and `1`/`2`/`3` jump directly to each action once the menu is up.

The FAB reuses the same modal components already shipped in 2.1.0, so the fetch/format/submit paths are unchanged. This is purely a UX shortcut that removes clicks from the daily workflow. NC Mail and NC Talk don't currently expose stable third-party message-action APIs at NC 30, so a deep app-specific integration (a "Log to SuiteCRM" button embedded in the Mail message header, or on a Talk conversation menu) is deferred until a supported extension slot lands.

### Added

- **Global Quick Actions floating action button** (`QuickActionsFab.vue`): fixed to the bottom-right of every Nextcloud page for signed-in users linked to SuiteCRM. Opens a three-item menu (Log Talk conversation, Link Deck card, Convert email to Case), each opening the corresponding modal from 2.1.0. Invisible when the user is not connected to SuiteCRM (rather than opening a dead-end "please connect first" modal).
- **Keyboard shortcut `Cmd/Ctrl+Shift+K`** toggles the FAB menu open from anywhere in Nextcloud. Once open, `1` selects Talk, `2` selects Deck, `3` selects Email. Menu caption shows the platform-aware key label (`Cmd` on Mac, `Ctrl` elsewhere).
- **`Listener\AddQuickActionsScriptListener`**: registered against `BeforeTemplateRenderedEvent` in `Application::register()`. Injects the FAB script on every full-page render, skipped for unauthenticated visitors so the loader never runs for guests.
- **`src/quickActions.js` + webpack entry**: mounts the FAB into a self-injected `#suitecrm-quick-actions-fab` div appended to `<body>`. Guards against double-mount when a misconfigured reverse proxy duplicates the script tag.

## 2.2.0 – 2026-07-22

Three new dashboard widgets (**My open SuiteCRM Cases**, **My open SuiteCRM Tasks**, and **My SuiteCRM pipeline**) round out the Nextcloud home dashboard for role-diverse SuiteCRM users. The two workload widgets follow the same shape as the existing schedule widget shipped in 2.0.x; the pipeline widget adds a per-user framing preference so reps whose deals close on a quarterly cadence, reps chasing top-value long-tail deals, and reps forecasting against a weighted-value target each see their pipeline the way they think about it.

No changes to the 2.1.x write features or the read providers. Existing installs upgrade cleanly; the new widget appears in the Nextcloud "Edit widgets" panel and stays hidden by default until the user enables it.

### Added

- **`My open SuiteCRM Cases` dashboard widget** (`SuiteCRMCasesWidget`): third dashboard widget, sitting below the reminder widget (order 10) and the schedule widget (order 20) at order 30 so the morning-glance view surfaces "what's happening today" first and "what's still open" second. Implements the same `IWidget` + `IAPIWidget` + `IAPIWidgetV2` + `IIconWidget` quad as the schedule widget, so both the classic Vue-mounted dashboard and the NC 30+ server-side API-rendered dashboard render items identically. Empty-state message is Case-specific (`No open SuiteCRM Cases`) via `IAPIWidgetV2`.
- **`SuiteCRMAPIService::getMyCases(url, token, userId, limit=20)`**: fetches Cases assigned to the current user and filters out terminal statuses (`Closed`, `Rejected`, `Duplicate`) client-side because SuiteCRM 8.10.x's JSON:API filter surface has no reliable NOT-IN operator. An earlier investigation established that `contains` is rejected and boolean-combining multiple `[eq]` filters degrades to top-level OR on 8.4/8.5. Sorts by priority (P1/High, then P2/Medium, then P3/Low, then unknown) then oldest-first within the same priority tier, so the widget surfaces high-severity long-open Cases at the top. Tags each row with `type='case'`, `age_days` (int), and `priority_rank` (int) for the frontend. A safety guard returns `[]` when the caller hasn't stored their SuiteCRM `user_id` yet, preventing an unscoped query that would return every Case in the tenant.
- **`GET /apps/njordium_suitecrm/my-cases`**: controller endpoint on `SuiteCRMAPIController` that the widget calls with a two-minute poll. Same 400-when-no-token / 401-on-SuiteCRM-error / 200-with-payload shape as the existing `/upcoming` endpoint so the frontend can reuse the same error handling.
- **`src/views/Cases.vue` + `src/cases.js`**: Vue widget mount for the classic dashboard path. Mirrors `Calendar.vue` so a rep familiar with the schedule widget finds the same interaction model: click a row to open the Case in SuiteCRM, connect-button empty state, 120-second polling loop paused when the browser tab is hidden. The Case number is prefixed to the main text so a rep with a Case number in mind can spot the right row without opening it; the subline shows `priority, status, "N days open"` (or `opened today` for age 0).
- **9 PHPUnit tests for `getMyCases()`** on `SuiteCRMAPIServiceTest`: terminal-status filtering (all three terminal values covered), priority sort with `P1/P2/P3` labels, priority sort with `High/Medium/Low` labels, unknown priority sorts last, limit respected, upstream error envelope propagation, `type`/`age_days`/`priority_rank` tagging invariant, empty-user-id safety guard (unscoped query must never fire).

### Changed

- **`SuiteCRMAPIService`** gains three private constants: `CLOSED_CASE_STATUSES`, `CLOSED_TASK_STATUSES` (single points of update if a customer install adds a Studio-defined terminal status), and `PRIORITY_ORDER` (weight table covering both the stock `P1/P2/P3` and the relabelled `High/Medium/Low` label sets that SuiteCRM 8 installs ship inconsistently). All internal implementation detail, no API contract change.

### Added (My open SuiteCRM Tasks widget)

- **`My open SuiteCRM Tasks` dashboard widget** (`SuiteCRMTasksWidget`): fourth dashboard widget, order 40 (below Cases at 30 so a rep scanning down sees Cases, which are external escalations, before Tasks, which are internal workload). Same `IWidget` + `IAPIWidget` + `IAPIWidgetV2` + `IIconWidget` quad as the Cases widget. Server-side rendered subline handles the relative due-date phrasing (`due today`, `due tomorrow`, `overdue by N days`, `due in N days`, or `no due date`) so the API-rendered dashboard path is as informative as the classic Vue mount. Empty-state message is Task-specific (`No open SuiteCRM Tasks`).
- **`SuiteCRMAPIService::getMyTasks(url, token, userId, limit=20)`**: fetches Tasks assigned to the current user and filters out terminal statuses (`Completed`, `Deferred`) client-side, keeping the actionable set (`Not Started`, `In Progress`, `Pending Input`), the same disposition vocabulary the calendar widget uses via `UPCOMING_MODULES.overdue_statuses`. Distinct from `getUpcoming()`: this method surfaces every actionable Task assigned to the user, including undated Tasks the calendar widget drops (a common miss in SuiteCRM 8 where reps create Tasks without setting a due date and then never see them again). Sorts by priority DESC, then due-date ASC with undated Tasks moved to the tail of each priority tier (a dated Task carries an urgency signal an undated one does not), then `date_entered` ASC as a stable tiebreaker for undated Tasks (older creation surfaces above fresher creation). Tags each row with `type='task'`, `due_ts` (`int|null`, null for undated or malformed dates), and `priority_rank` (int). Empty-user-id safety guard mirrors `getMyCases()`.
- **`GET /apps/njordium_suitecrm/my-tasks`**: controller endpoint on `SuiteCRMAPIController`. Same 400/401/200 response shape as `/upcoming` and `/my-cases`.
- **`src/views/Tasks.vue` + `src/tasks.js`**: Vue widget mount for the classic dashboard path. Uses `moment.unix(due_ts).fromNow()` for locale-aware relative due-date labels (`in 3 days`, `2 days ago`) instead of raw ISO dates. Subline shape is `priority, due-label` or `priority, no due date`.
- **8 additional PHPUnit tests for `getMyTasks()`** on `SuiteCRMAPIServiceTest`: terminal-status filtering (all three actionable states surface, both terminal states drop), priority + due-date compound sort, undated-Tasks-sort-last-within-tier invariant, undated-tie-break by `date_entered`, malformed-`date_due` fallback to undated (no crash), tagging invariant, upstream error propagation, limit respected.

### Added (My SuiteCRM pipeline widget)

- **`My SuiteCRM pipeline` dashboard widget** (`SuiteCRMPipelineWidget`): fifth dashboard widget, order 50 (bottom of the SuiteCRM widget stack, since pipeline value tends to be a strategic morning check rather than an operational one). Framing is user-selectable via the `pipeline_mode` personal preference, so a rep whose deals close on a quarterly cadence, a rep chasing top-value long-tail deals, and a rep forecasting against a weighted-value target each get their own default view. Subline shape shifts with the selected mode: `closing_quarter` shows `stage, closes YYYY-MM-DD, $amount`, `top_value` shows `stage, $amount, N% probability`, `weighted` shows `stage, $weighted weighted (of $amount at N%)`. All three modes render server-side via `IAPIWidgetV2` so the API-rendered dashboard variant is as informative as the classic Vue mount, with mode-specific empty-state messages (`No SuiteCRM Opportunities closing this quarter` vs `No open SuiteCRM Opportunities`).
- **`SuiteCRMAPIService::getMyPipeline(url, token, userId, mode, limit)`**: fetches open Opportunities assigned to the current user and applies mode-specific filtering + sorting. All modes drop terminal `sales_stage` values (`Closed Won`, `Closed Lost`) client-side. `closing_quarter` further filters to `close_date` inside the current calendar quarter (undated deals excluded from this mode entirely, since the whole point of the mode is deals that need to land THIS quarter) and sorts by `close_date` ASC. `top_value` sorts by `amount` DESC across all open Opportunities regardless of close date. `weighted` sorts by `amount x probability/100` DESC. Unknown mode strings snap to `DEFAULT_PIPELINE_MODE` rather than crashing (an old bookmarked URL or a hand-edited preference row shouldn't kill the widget). Tags each row with `type='opportunity'`, `close_ts` (`int|null`), `amount_num` (float), `probability_num` (float), and `weighted_num` (float).
- **`GET /apps/njordium_suitecrm/my-pipeline?mode=<mode>`**: controller endpoint on `SuiteCRMAPIController`. Same 400/401/200 response shape as `/upcoming`, `/my-cases`, `/my-tasks`.
- **`pipeline_mode` personal preference**: exposed as an `NcSelect` in Personal Settings, SuiteCRM integration, **Dashboard widget preferences** with three options. A live hint under the selector explains the effect of each mode. Validated on both write (`ConfigController::USER_ALLOWED_KEYS`) and read (`Settings\Personal::getForm()` snaps unknown values back to the default).
- **`src/views/Pipeline.vue` + `src/pipeline.js`**: Vue widget mount. Reads the mode from initial state at construction time so it doesn't need to re-fetch on every polling cycle. Currency amounts formatted with `toLocaleString` for locale-aware thousands separators.
- **`SuiteCRMAPIService` gains two new public constants**: `PIPELINE_MODES` (the canonical list of the three valid mode strings) and `DEFAULT_PIPELINE_MODE` (`closing_quarter`). Public because the settings-form validator and the initial-state provider need them; private constants would force those consumers to duplicate the list.
- **10 additional PHPUnit tests for `getMyPipeline()`** on `SuiteCRMAPIServiceTest`: terminal-stage filter, `top_value` amount-DESC sort, `weighted` amount-times-probability sort with numeric assertions on the computed weighted values, `closing_quarter` quarter-window filter (dated-in-quarter kept, dated-out-of-quarter dropped, undated dropped), `closing_quarter` close-date-ASC sort, unknown-mode fallback to default, tagging invariant on all five computed fields, upstream error propagation, limit respected, safety guard on empty `user_id`.

## 2.1.1 – 2026-07-22

Critical hotfix for the write features shipped in 2.1.0. Every POST from `SuiteCRMAPIService::createRecord()` (task-followup, log-note, link-deck-card, email-to-case) returned HTTP 405 Method Not Allowed against real SuiteCRM 8.10.x installs. Reads continued to work.

### Fixed

- **`SuiteCRMAPIService::createRecord()` endpoint route**: the creation URL is `POST /Api/V8/module` (no module suffix); the module name travels in `data.type` of the JSON:API payload. The previous `POST /Api/V8/module/{module}` form happened to work for Tasks but SuiteCRM 8.10.1 rejects it for Cases (and probably several other modules) with 405 `Method not allowed. Must be one of: GET`. This is the JSON:API-compliant route, so it works uniformly across modules. PHPUnit tests updated to assert the new invariant (module always in `data.type`, never in URL path).
- **`SuiteCRMAPIService::request()` URL prefix**: dropped the `/index.php/` segment (a first-pass hotfix attempt during the same debugging session). SuiteCRM 8.10.x's URL rewriter accepts both `/Api/V8/` and `/Api/index.php/V8/` for GET (both hit the same controller), so read call sites are unaffected. Keeping the shorter form for consistency with the SuiteCRM V8 documentation.

## 2.1.0 – 2026-07-22

Four user-intent write features that turn Nextcloud activity into linked SuiteCRM records. Personal Settings, **Quick actions to SuiteCRM** gains three buttons: Log Talk conversation as a Note, Link Deck card to SuiteCRM record (with reciprocal comment on the card), Convert email to Case (paste form). A reusable `TaskFollowupModal.vue` component ships too; the widget-item wire-up that surfaces it is deferred to a follow-up release.

The write path is protected end-to-end: every endpoint gates on the OAuth session token, whitelists source and target modules to the eight the fork integrates with, and propagates SuiteCRM errors as HTTP 502 with the original envelope so the frontend can distinguish user-fault from server-fault. Four backend endpoints share the same `SuiteCRMAPIService::createRecord()` primitive, and 37 PHPUnit tests cover the failure modes.

Existing 2.0.x behaviour is unchanged. This is purely additive. Read-only deployments can install this version safely; nothing writes to SuiteCRM until a user explicitly clicks a Quick Action button.

Also adds the `occ njordium_suitecrm:test-connection --push-test` diagnostic that was originally introduced as the write-features assurance gate. It stays useful for verifying a fresh SuiteCRM instance's `client_credentials` grant is properly configured before rolling the app out to end users.

### Added

- **`occ njordium_suitecrm:test-connection --push-test`**: after the five read-side checks pass, exchange the OAuth2 `client_credentials` grant for an admin-scoped access token, POST a throwaway Task record to `/Api/V8/module/Tasks`, and report the created record ID plus a UI path to inspect and delete it. Uses no per-user token and does not touch `oc_preferences`. Safe to invoke against production instances since the record is clearly marked `occ push-test` in name and `Safe to delete` in description.
- **`SuiteCRMAPIService::createRecord(url, token, userId, module, attributes)`**: wraps caller-supplied attributes in the V8 JSON:API envelope (`{data: {type, attributes}}`) and POSTs to `/module/{module}` with the correct `application/vnd.api+json` content type. Delegates to the existing `request()` method so token-refresh retry and error envelopes stay in one place.
- **`SuiteCRMAPIService::linkRecord(url, token, userId, fromModule, fromId, relationship, toType, toId)`**: attaches one SuiteCRM record to another via a named relationship using the JSON:API resource-linkage envelope.
- **`SuiteCRMAPIService::request()` gained a `bool $jsonBody = false` optional parameter** that switches the request body from form-encoded to JSON with the `vnd.api+json` content type. Backward-compatible: read call sites keep their existing behaviour.
- **PHPUnit coverage for `createRecord()` + `linkRecord()`** (5 new tests on `SuiteCRMAPIServiceTest`): envelope shape guarantees, endpoint routing, URL-encoding of module names, error-envelope propagation.
- **`POST /apps/njordium_suitecrm/task-followup`**: new controller endpoint on `SuiteCRMAPIController` that creates a follow-up SuiteCRM Task linked back to a source record via `parent_type` + `parent_id`. Whitelists source modules (Meetings, Calls, Tasks, Contacts, Accounts, Leads, Opportunities, Cases; anything else 400s with a clear message), validates priority to SuiteCRM's High/Medium/Low enum, propagates SuiteCRM errors as 502 with the original envelope.
- **`TaskFollowupModal.vue`** component (`src/components/`): reusable dialog for capturing task name + due date + priority + notes, POSTs to `/task-followup`, emits `created` on success. Not yet wired into either dashboard widget; that surface work lands in a follow-up release.
- **PHPUnit coverage for the task-followup endpoint** (10 new tests on new `SuiteCRMAPIControllerTest`): auth guard, name/sourceId/priority validation, source-module whitelist, `parent_type`/`parent_id` linking, optional `date_due` omission, all 8 whitelisted parent modules accepted, error propagation as 502.
- **`POST /apps/njordium_suitecrm/log-note`**: generic Note-creation endpoint on `SuiteCRMAPIController`. Creates a SuiteCRM Note attached to any whitelisted parent (Contacts, Accounts, Leads, Opportunities, Cases, Meetings, Calls, Tasks) via `parent_type` + `parent_id`. Intended as the primitive that later features compose (Talk conversation transcript to Note, Deck card to Opportunity two-way link, optional source-email log on a Case). Same auth guard + whitelist + 502-on-SuiteCRM-error semantics as `/task-followup`.
- **8 additional PHPUnit tests for `logNote()`** on `SuiteCRMAPIControllerTest`: matching failure-mode coverage to the task-followup tests plus a `@dataProvider` verifying all 8 whitelisted target modules accept Note attachment.
- **`POST /apps/njordium_suitecrm/link-deck-card`**: SuiteCRM side of the Nextcloud Deck card to SuiteCRM record link. Creates a Note on the target record with a stable body format (`Linked from Nextcloud Deck card "<title>", URL: <url>`) that SuiteCRM users can search or filter on. Validates the URL, falls back to the URL as the visible label when the card title is empty, appends an optional free-text `extraNote`. Deck-side comment on the card is handled by the frontend via NC Deck's OCS API (no cross-app coupling on the server).
- **9 additional PHPUnit tests for `linkDeckCard()`** on `SuiteCRMAPIControllerTest`: auth guard, URL validation (empty + malformed), target module whitelist, Note-body format contract (searchable "Linked from Nextcloud Deck card" string), title fallback when card title is empty, optional extraNote appending, error propagation as 502.
- **`POST /apps/njordium_suitecrm/email-to-case`**: fourth (and final) write-feature endpoint of the initial roadmap. Turns an email (inbound from NC Mail, forwarded, or pasted into a form) into a SuiteCRM Case. Composes a stable, searchable Case body with `From:` and `Date:` headers followed by the message text; each header is emitted only when the caller actually supplied that field, so paste-form callers with partial metadata don't leave dangling `From:` lines. Always sets `status='New'` explicitly to guard against a future SuiteCRM default change silently mis-routing email-sourced Cases. Contact/Account linking (matching sender email to an existing SuiteCRM Contact) is deferred to a follow-up: the frontend already has the picker + search infrastructure planned there, and keeping the operations separate keeps this endpoint composable.
- **9 additional PHPUnit tests for `emailToCase()`** on `SuiteCRMAPIControllerTest`: auth guard, subject/body/priority validation, stable body composition with full sender metadata (name + email + date), display-name fallback (email-only sender doesn't render angle brackets), no-metadata fallback (body-only), Date-header-only case, explicit `status='New'` guard, error propagation as 502.
- **`src/components/SuiteCRMRecordPicker.vue`**: reusable Vue component for picking a SuiteCRM record via URL paste. Client-side URL parsing mirrors the backend `RecordUrlParser` regex so both the reference-provider preview cards and the write-side picker accept the same URL shapes. Emits `{module, id}` on parse success. Currently orphan; first consumer is `TalkToNoteModal.vue` below, with further consumers landing alongside the Deck link and Email-to-Case UIs.
- **`src/components/TalkToNoteModal.vue`**: two-step user flow. Pick a Nextcloud Talk conversation from the user's list (via Talk OCS API `apps/spreed/api/v4/room`), pick a SuiteCRM record via `SuiteCRMRecordPicker`. On submit, fetches the last N messages (10/25/50/100/200) from the picked conversation, formats as a markdown transcript with speaker + timestamp headers, POSTs to `/log-note`. System messages (joins/leaves/permission changes) are filtered out; only user messages land in SuiteCRM. Graceful degradation if Talk isn't installed.
- **`src/components/LinkDeckCardModal.vue`**: user pastes an NC Deck card URL, picks a SuiteCRM record, optionally supplies a card title + extra note. On submit, POSTs to `/link-deck-card` to create the SuiteCRM Note, then POSTs to Deck's OCS API (`apps/deck/api/v1.0/cards/{cardId}/comments`) to add the reciprocal comment on the card. If the Deck comment fails (Deck not installed, insufficient permissions, etc.) the SuiteCRM side still succeeds and the user sees a warning about the failed reciprocal.
- **`src/components/EmailToCaseModal.vue`**: paste-form for the fourth write feature. User enters subject + body (required) plus optional sender name/email/date and picks priority. POSTs to `/email-to-case` which composes the stable Case body with `From:`/`Date:` headers when metadata is present. No cross-app integration in the MVP: NC Mail's action-hook API would be a nicer trigger but is out of scope for the initial roadmap.
- **`src/components/PersonalSettings.vue`** gained a "Quick actions to SuiteCRM" section (shown when the user is connected) with three buttons (Log Talk conversation, Link Deck card, Convert email to Case), each opening the corresponding modal.

## 2.0.1 – 2026-07-22

Prep release for Nextcloud App Store submission. No functional change: 1.9.x and 2.0.0 users can skip this if they only install direct from the GitHub release zip; upgrading is only necessary once we publish on apps.nextcloud.com.

### Changed

- **`appinfo/info.xml`** now uses the SPDX license identifier `AGPL-3.0-or-later` in place of the deprecated `agpl` shorthand. Nextcloud 31 and later require SPDX; the shorthand was only ever valid on NC 30 and would have caused App Store schema validation to fail on submit.
- **`appinfo/info.xml`** self-references the App Store XSD (`xmlns:xsi` + `xsi:noNamespaceSchemaLocation="https://apps.nextcloud.com/schema/apps/info.xsd"`) so IDEs and CI validators catch schema drift before it hits the store.
- **`appinfo/info.xml`** now declares `<repository type="git">https://github.com/njordium/integration_suitecrm</repository>` for App Store metadata completeness.

### Added

- **`.github/workflows/release.yml`** now emits `njordium_suitecrm-<version>.tar.gz` alongside the existing `.zip`, both with matching `.sha256` files. The Nextcloud App Store submission API requires `tar.gz`; direct-install admins can continue using the zip. Both archives are built from the same rsync-staged tree so the file contents are byte-identical.

## 2.0.0 – 2026-07-22

### BREAKING CHANGES

- **App id renamed** from `integration_suitecrm` to `njordium_suitecrm`. The Nextcloud App Store record for `integration_suitecrm` still points to Julien's original 1.0.3 (last updated November 2021), and every field the NC admin panel displays for our fork was being pulled from that stale record. Renaming the id de-couples the fork from that record for good.
- The install folder is now `custom_apps/njordium_suitecrm/`. The old `custom_apps/integration_suitecrm/` will no longer be recognised.
- The `occ` command is now `occ njordium_suitecrm:test-connection`. The old command name will not resolve.
- Route names, OAuth redirect URI paths, config-app keys, and Vue translation domains all use the new id.

### Migration

A `Migration\CopyLegacyAppConfig` Repair step is registered under `<post-migration>` and runs automatically on `occ upgrade`. It copies every row from `oc_appconfig` and `oc_preferences` where `appid = 'integration_suitecrm'` under the new app id, skipping any target row that already exists (idempotent). Legacy rows are **not deleted**; a follow-up 2.1.0 Repair step will do that once 2.0.0 has been stable for a while, keeping a clean rollback path in the meantime.

**Upgrade steps** (Docker example, adapt paths for LXC/bare-metal):

```bash
# Disable the old app
docker exec -u www-data <container> php occ app:disable integration_suitecrm

# Drop the new folder (zip contains a top-level `njordium_suitecrm/`)
unzip njordium_suitecrm-v2.0.0.zip -d /path/to/nextcloud/custom_apps/

# Enable the new app. This fires the Migration Repair step,
# which copies your admin OAuth config + every user's OAuth tokens
docker exec -u www-data <container> php occ app:enable njordium_suitecrm

# Optional: after verifying everything works
rm -rf /path/to/nextcloud/custom_apps/integration_suitecrm
```

**Users do not need to reconnect**. Their per-user OAuth tokens carry over via the Repair step's copy of `oc_preferences`. The admin OAuth client id/secret/instance URL and authorize path also carry over automatically.

### Rollback

If v2.0.0 misbehaves for you, the old rows are untouched:

```bash
docker exec -u www-data <container> php occ app:disable njordium_suitecrm
docker exec -u www-data <container> php occ app:enable integration_suitecrm
```

Everything's back where it was on 1.9.1.

### What this fixes

The "Latest updated: November 2021" line in the NC admin panel goes away. The new app id doesn't match any App Store record, so NC has no stale cache to render.

### Added

- **`lib/Migration/CopyLegacyAppConfig.php`**: `IRepairStep` implementation covering the id rename described above. Idempotent, safe on fresh installs, silent no-op when there is nothing to migrate. Registered in `appinfo/info.xml` under `<repair-steps><post-migration>`.

### Changed

- `<id>` in `appinfo/info.xml`: `integration_suitecrm` to `njordium_suitecrm`.
- `Application::APP_ID`: same.
- Webpack bundle output filenames: `integration_suitecrm-{personalSettings,adminSettings,dashboard,calendar}.js` to `njordium_suitecrm-*.js`.
- Release workflow (`.github/workflows/release.yml`): staging directory, zip filename, artifact name, and README install instructions all reference the new id. The GitHub repo URL and Composer package identifier stay `njordium/integration_suitecrm` since those correspond to the repository path, not the Nextcloud app id.
- All Vue `t()` translation domains, `loadState()` calls, `generateUrl()` prefixes.
- All documentation examples (`README.md`, `docs/getting-started.md`, `docs/proxmox-lxc.md`, `docs/reverse-proxy.md`) reference the new id in commands and paths.

## 1.9.1 – 2026-07-22

Follow-up release covering the audit-driven cleanup and enhancement work that closed several upstream `julien-nc/integration_suitecrm` issues after the 1.9.0 tag.

### Added

- **`docs/getting-started.md`**: end-to-end admin walkthrough from an unconnected SuiteCRM install to a working integration with smoke tests. Explicit LDAP note calls out that AD-backed users must use the authcode flow, not the Advanced password fallback (closes upstream issues [#2](https://github.com/julien-nc/integration_suitecrm/issues/2), [#9](https://github.com/julien-nc/integration_suitecrm/issues/9), [#11](https://github.com/julien-nc/integration_suitecrm/issues/11)).
- **Calendar widget: past-due-not-dispositioned items**: `SuiteCRMAPIService::getUpcoming()` now widens its date filter to include the last 30 days (configurable) and returns past-due Meetings/Calls whose status is still `Planned` and Tasks whose status is not `Completed`/`Deferred`. Each row carries an `is_overdue` flag for frontend badging. Previously the filter was `date > now` and past-due rows silently vanished. Closes upstream [#8](https://github.com/julien-nc/integration_suitecrm/issues/8).
- **Admin UI "Reset connection" button**: `AdminSettings.vue` gets a warning-styled button that opens a confirmation dialog and DELETEs `/apps/integration_suitecrm/admin-config`, clearing `oauth_instance_url`, `client_id`, `client_secret`, and `oauth_authorize_path`. Individual user tokens are intentionally left in place. They'll fail their next SuiteCRM request and the per-user OAuth flow restarts automatically. Closes upstream [#14](https://github.com/julien-nc/integration_suitecrm/issues/14).
- **PHPUnit regression coverage** for the new work: `testResetAdminConfigDeletesAllExpectedKeys` on `ConfigControllerTest`, and four new methods on `SuiteCRMAPIServiceTest` covering `getUpcoming`'s past-due / dispositioned / future-with-Held-status / Task-vocabulary paths plus a structural guard that every `UPCOMING_MODULES` row declares `overdue_statuses`.

### Changed

- `README.md`: pointer to `docs/getting-started.md` at the top; mention of the `occ integration_suitecrm:test-connection` diagnostic in the Features and Configuration sections; Deployment scenarios now points at `docs/reverse-proxy.md` and `docs/proxmox-lxc.md` rather than duplicating them inline.
- `AUTHORS.md`: add Kim Haverblad as fork maintainer.
- `CHANGELOG.md`: 1.9.0 backfill covering the audit-driven work (this file).

### Removed

- `.tx/config` and the surrounding `.tx/` directory. The Transifex configuration pointed at a `translationfiles/` tree that hasn't existed in the fork since l10n was deferred in 1.8.0.
- `.l10nignore`, only meaningful for apps in Nextcloud's l10n pipeline; the fork isn't.
- Root `makefile`. Its `appstore` target references `translationfiles/`, `l10n.pl`, `crowdin.yml`, and various other files that don't exist in the fork. Superseded by `.github/workflows/release.yml`.

### Fixed

- **PHPStan hotfix**: dropped an `?? []` fallback that PHPStan level 5 rejected as dead code. `UPCOMING_MODULES` provably declares `overdue_statuses` on every row.
- **Vue hotfix**: `NcButton` and the `NcDialog` button descriptors now use the current Nextcloud/vue v9 `variant` prop rather than the deprecated `type`.

## 1.9.0 – 2026-07-21

Backfills the full body of work landed between the 1.8.0 tag and the 1.9.0 release cut on commit `c2d5f55`. The theme was audit-and-fix: every prior release got a substantive review, most of them uncovered latent bugs that were shipped-but-never-run, and each fix carries a live-verification note against the docker container or the user's production Ubuntu 24.04 LXC.

### Added

- **Enriched OAuth error envelope** (`SuiteCRMAPIService::requestOAuthAccessToken()`): the token-exchange call now returns `error_kind`, `http_status`, `error_code`, `error_description`, and the raw body on failure paths. `ConfigController::oauthCallback()` uses these to produce admin-friendly guidance for `local_server_blocked` (SSRF guard), `401 / invalid_client` (SHA-256 vs bcrypt trap + redirect_uri mismatch), and transport-level failures. Previously the actionable-error branches were unreachable dead code because the service's outer `catch(\Throwable)` swallowed every exception into `['error' => msg]`.
- **Hardened release workflow** (`.github/workflows/release.yml`): inline lint/test gate (PHP syntax across 8.2/8.3/8.4, PHPStan, PHPUnit, JS lint, stylelint, webpack build, bundle presence check) runs before packaging; a version-sync step fails the release if the pushed tag does not match `<version>` in `appinfo/info.xml`; SHA-256 checksum is emitted alongside the zip; release body corrects `apps/` to `custom_apps/`. Dry-run twice via `workflow_dispatch` to prove the whole build path actually runs.
- **`occ integration_suitecrm:test-connection` diagnostic**: five-check `occ` command covering admin-config completeness, SSRF-guard interaction with the target host, HTTP reachability, authorize endpoint (accepts 200/302/303/307/308 so all SuiteCRM 8.x variants pass), and the token endpoint (POST with a bogus grant, expects 400 with `unsupported_grant_type`). Live-run against SuiteCRM 8.10.1: 8/8 green with the correct admin config.
- **Reverse-proxy deployment reference** (`docs/reverse-proxy.md`): nginx (NC-only + NC+SuiteCRM), Apache with a working PHP-FPM handler block (do not copy-paste without it: mod_php-less Apache would serve `.php` files as text), Cloudflare Tunnel, and a dedicated "About allow_local_remote_servers" section that clarifies the SSRF guard resolves the target IP rather than caring about the request path.
- **Proxmox LXC production reference** (`docs/proxmox-lxc.md`): grounded in a live Ubuntu 24.04 LTS + PHP 8.3 + NC 33 LXC. Two-LXC layout (Nextcloud + SuiteCRM), `pct create` commands, install scripts using `libapache2-mod-php` (matching what the popular Proxmox helper-script installers actually produce), `www-data` classic crontab for NC cron with an optional systemd-timer alternative, PVE-host nginx pointer, backup script preserving the NC config.php `secret` and the SuiteCRM OAuth2 keypair, upgrade path.
- **Cross-module search widened to first_name for person modules**: Contacts and Leads now filter on both `last_name` and `first_name` via per-attribute requests + result dedup by `module|id`. First-name-only queries (typing `Serena` and expecting `Serena Arent`) now return hits; before the widening they returned zero. Documented the deliberate rejection of `filter[operator]=or` (SuiteCRM 8.4/8.5 DBAL applies OR at the top of the WHERE clause and returns every non-deleted row).
- **PHPUnit regression suite for `SuiteCRMAPIService::search()`** (`tests/php/Service/SuiteCRMAPIServiceTest.php`): 11 tests covering every historical search bug: the `contains` operator regression, the `full_name` computed-field trap, the missing `first_name` in `name_attrs`, the invalid `date_sent` on Emails, and the error-handling semantics (partial-attribute failure suppresses warnings, total failure logs). Behavioural tests use a partial mock of the service; structural tests use reflection on the private `SEARCH_MODULES` const.
- **`IAPIWidgetV2` support** on both dashboard widgets: alongside the existing `IWidget + IAPIWidget + IIconWidget` interfaces, both widgets now implement `IAPIWidgetV2` and return a `WidgetItems` envelope with a SuiteCRM-specific `emptyContentMessage` ("No SuiteCRM notifications!" / "No upcoming SuiteCRM events") instead of the dashboard's generic "No entries" placeholder. Falls back cleanly to V1 on any NC that doesn't probe V2.

### Changed

- **`search()` operator reverted to `like` with wildcards**: SuiteCRM 8.10.1 responds `400 Filter operator contains is invalid`, so the `%wildcards%` pattern is back and the operator is `like`. Live-verified against the container.
- **Emails module `fields` list**: dropped `date_sent`; that column does not exist on SuiteCRM 8's Email bean and requesting it responded `400 The following field in Email module is not found: date_sent`.
- **`TestConnection` authorize-path normalisation**: matches `ConfigController::oauthAuthorizeUrl()`'s pattern (`rtrim($url, '/') . '/' . ltrim($path, '/')`) so an admin who sets `oauth_authorize_path=Api/authorize` (no leading slash) does not get a false-negative from the diagnostic while the real OAuth flow works fine. Token endpoint path is now derived from the authorize path (`preg_replace /authorize$/ to /access_token`) so SuiteCRM installs upgraded from 7.x with the `/legacy/oauth2/*` layout also get a valid token check.
- **`softprops/action-gh-release` bumped v2 to v3** and **`actions/upload-artifact` bumped v4 to v7** to eliminate GitHub's Node 20 deprecation warnings.
- **`README.md`** rewritten with deployment-scenario matrix (Docker, reverse proxy, cloud VPC, Proxmox LXC), install caveats (js/ bundle build required for tarball installs), the `allow_local_remote_servers` gotcha, and a troubleshooting section keyed by symptom.

### Fixed

- **ConfigController constructor signature drift**: an earlier commit added `LoggerInterface` at position #10, but `ConfigControllerTest` still constructed the SUT with ten positional arguments. A follow-up commit updated the test to inject the logger mock and pass it at the right position. CI PHPUnit went from red to green.
- **`.phpunit.cache` leak into the release zip**: the PHPUnit gate wrote `.phpunit.cache/test-results` into the workdir before the packaging step ran. A follow-up added it and half a dozen other transient caches (`.php-cs-fixer.cache`, `.phpcs-cache`, `.tool-versions`, `.node-version`, `.php-version`) to the rsync exclude list.
- **SuiteCRM unzip in `docs/proxmox-lxc.md`** would silently break `DocumentRoot`: the SuiteCRM 8.x GitHub-release ZIP ships with a top-level `SuiteCRM-<version>/` directory. Fix: unpack to a scratch dir, then `cp -a /tmp/scrm-unpack/SuiteCRM-*/. /var/www/suitecrm/`.
- **Test-connection expected output** in the Proxmox doc updated for the HTTP 307 handling and the new "Derived token endpoint path" line.
- Notification triage: 13 stale CI-failure notifications bulk-closed after later commits landed green.

### Verified live

- OAuth authcode flow end-to-end against SuiteCRM 8.10.1: authorize, consent, callback, token exchange, tokens stored, `user_name` + `user_id` populated on connect.
- Unified search from Nextcloud 30 against SuiteCRM 8.10.1: `Serena` returns the "Serena Arent" Contact, `Arent` also returns the Contact, `AtoZ` returns the Account + Opportunity, all 10 diverse queries return HTTP 200.
- Both dashboard widgets render with icons on the Nextcloud dashboard.
- `occ integration_suitecrm:test-connection` runs green with the stock config and also green with a no-leading-slash `oauth_authorize_path` value.
- Release workflow dry-run via `workflow_dispatch` produces a valid 7.03 MB zip with the correct layout (`lib/` + `js/` + `appinfo/` + `docs/` + `README` + `COPYING`, no `tests/` or `composer.json` or `.phpunit.cache/`).
- `nextcloud.log` clean of `integration_suitecrm` warnings after 20 minutes of diverse activity.
- Ubuntu 24.04 + PHP 8.3 + NC 33 target OS/version validated read-only against the user's production LXC. Every command in `docs/proxmox-lxc.md` grounded in what the popular installer scripts actually produce.

## 1.8.0 – 2026-07-01
### Changed
- Modernised PHP 7.4-style classes to PHP 8 constructor property promotion: `SuiteCRMWidget`, `Settings\Personal`, `Settings\Admin` (property docblocks removed, all readonly-style dependencies now declared in the constructor signature)
- README rewritten as a proper user-facing document: feature list, requirements, install/config instructions, development workflow, contributing pointer, updated license credit. Replaces the previous ~30-line placeholder

### Deferred
- NC Mail contact card via `IMailContactProvider`. Depends on `nextcloud/mail` being installed for meaningful testing; will land after a docker-based test environment is stood up
- Full l10n restoration (Transifex or committed `en.json` baseline). English strings work fine as fallback; deferred until translations are actually needed

## 1.7.0 – 2026-07-01
### Added
- **composer.json** for PHP tooling (PHPUnit 10, PHPStan 1.11, nextcloud/ocp stubs, PSR-4 autoload for `OCA\SuiteCRM\`)
- **phpunit.xml** with tests/php coverage of lib/
- **phpstan.neon** at level 5 covering all of lib/ (Application.php excluded because it depends on OC AppFramework stubs that don't survive plain analyse without a live NC codebase)
- **Test suite**:
  - `TokenStorageTest`: encryption on write, decryption on read, backward-compat plaintext migration, clear-all, empty-string short-circuits
  - `RecordUrlParserTest`: 5 valid-URL data-provider cases (query-string ordering, HTML-encoded ampersands, embedded in prose, mixed case), 6 rejection cases, module list assertion
- Extracted `RecordUrlParser` from `SuiteCRMReferenceProvider` as a pure static class, testable without instantiating IConfig/IL10N/IURLGenerator/etc.
- CI workflow expanded to run PHPUnit across PHP 8.1/8.2/8.3 and PHPStan on 8.2

### Changed
- CI workflow renamed to "Lint & Test"

## 1.6.0 – 2026-07-01
### Added
- **Reference provider** for SuiteCRM record URLs: paste a link like `.../index.php?module=Contacts&record=abc-123` into Talk, Notes, Deck, or any Nextcloud text field and it renders a rich card with the record's name and key attributes
- **Smart picker support**: the same provider implements `ISearchableReferenceProvider`, so the `@` smart picker in Talk/Notes exposes SuiteCRM as a search source (reusing the same `SuiteCRMSearchProvider`)
- Provider is registered via `IRegistrationContext::registerReferenceProvider` and cached per-user with the record's module:id key
- Supported modules for reference cards: Contacts, Accounts, Leads, Opportunities, Cases, Meetings, Calls, Tasks

## 1.5.0 – 2026-07-01
### Added
- New **SuiteCRM calendar** Nextcloud dashboard widget listing today's + next-7-days Meetings, Calls, and Tasks assigned to the current user, sorted chronologically
- `SuiteCRMAPIService::getUpcoming()`: data-driven fetch across Meetings/Calls/Tasks with server-side date + assignee filters; returns rows tagged with `type` and a normalised `event_ts` for client-side sorting
- New `GET /apps/integration_suitecrm/upcoming` endpoint
- Vue component `Calendar.vue` with the same visibility-loop pattern as the reminders widget

### Changed
- Widget order: reminders widget stays at order 10, the new calendar widget at order 20

## 1.4.0 – 2026-07-01
### Added
- Unified search now covers **Meetings**, **Tasks**, and **Emails** in addition to Contacts / Accounts / Leads / Opportunities / Cases
- Meeting results show start date; Task results show due date and priority; Email results show the sender name
- Cases show their case number in the subline

### Changed
- `SuiteCRMAPIService::search()` refactored to a data-driven loop over a `SEARCH_MODULES` table. New modules can be added by appending a single row
- `SuiteCRMSearchProvider` result formatting replaced repeated if/elseif chains with a `TYPE_TO_MODULE` map and `match()` expressions

### Fixed
- Regex-injection vulnerability in search: the user query was interpolated directly into `preg_match()`. Now passed through `preg_quote()`. Searches for characters like `.`, `/`, `(`, `?`, `+` no longer break the pattern or throw

## 1.3.0 – 2026-07-01
### Changed
- Modernised the settings UI to the Nextcloud v9 design system: replaced raw `<input>` and native `<button>` elements with `NcTextField`, `NcPasswordField`, `NcButton`, `NcCheckboxRadioSwitch`, and `NcNoteCard`
- Replaced legacy icon-class markup with Material Design icons from `vue-material-design-icons` (Login, Logout, CheckCircle, ContentCopy, KeyPlus, OpenInNew, CalendarSync)
- Adopted v9 prop naming: `v-model` on inputs, `variant` on buttons, `modelValue`/`update:modelValue` on switches
- Personal + Admin settings now inherit dark-mode, focus-ring, and accessibility styling from the design system automatically

### Added
- New `vue-material-design-icons` dependency

## 1.2.0 – 2026-07-01
### Added
- TokenStorage service: OAuth access tokens + refresh tokens are now encrypted at rest via OCP\Security\ICrypto
- Backward-compatible plaintext-to-encrypted migration on first read for installs upgraded from <= 1.1.x
- Calendar Companion panel in Personal Settings exposing Nextcloud URL, login, and a deep link to /settings/user/security for app-password generation. Feeds the per-user setup flow of the [suitecrm_nextcloud_calendar](https://github.com/njordium/suitecrm_nextcloud_calendar) SuiteCRM-side module
- GitHub Actions CI: lint + build (JS) and php -l (PHP 8.1/8.2/8.3) on push and PR

### Changed
- All token reads/writes now go through TokenStorage (ConfigController, SuiteCRMAPIController, SuiteCRMAPIService, SuiteCRMSearchProvider)
- Fixed CospendSearchProvider copy-paste docblock in SuiteCRMSearchProvider

## 1.1.0 – 2026-06-30
### Changed
- Fork: updated Nextcloud compatibility to NC25-34
- Migrated frontend from Vue 2 to Vue 3
- Updated all @nextcloud/* dependencies to current versions (axios v2, dialogs v5, vue v8, etc.)
- Replaced deprecated `Vue.prototype` globals with `app.config.globalProperties`
- Replaced `DashboardWidget`/`EmptyContent` (vue-dashboard) with `NcDashboardWidget`/`NcEmptyContent` from @nextcloud/vue
- Replaced `beforeDestroy` with `beforeUnmount` (Vue 3)
- Replaced `::v-deep` with `:deep()` (Vue 3)
- Moved notifier registration from Application constructor to `register()` (NC25+ best practice)
- Bumped Node engine requirement to >=20

## 1.0.2 – 2021-09-06
### Changed
- bump js libs

### Fixed
- hide credentials on login failure in server logs

## 1.0.1 – 2021-06-24
### Changed
- stop polling widget content when document is hidden
- bump js libs
- more explanations in README
- bump min NC version to 22

## 0.0.4 – 2020-11-24
### Changed
- new hint in perso settings when admin settings are not set
- bump js libs

### Fixed
- occ check-code warning

## 0.0.2 – 2020-10-22
### Added
* the app
