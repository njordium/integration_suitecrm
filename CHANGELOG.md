# Change Log
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

## 2.0.1 – 2026-07-22

Prep release for Nextcloud App Store submission. No functional change — 1.9.x and 2.0.0 users can skip this if they only install direct from the GitHub release zip; upgrading is only necessary once we publish on apps.nextcloud.com.

### Changed

- **`appinfo/info.xml`** now uses the SPDX license identifier `AGPL-3.0-or-later` in place of the deprecated `agpl` shorthand. Nextcloud 31 and later require SPDX; the shorthand was only ever valid on NC 30 and would have caused App Store schema validation to fail on submit.
- **`appinfo/info.xml`** self-references the App Store XSD (`xmlns:xsi` + `xsi:noNamespaceSchemaLocation="https://apps.nextcloud.com/schema/apps/info.xsd"`) so IDEs and CI validators catch schema drift before it hits the store.
- **`appinfo/info.xml`** now declares `<repository type="git">https://github.com/njordium/integration_suitecrm</repository>` for App Store metadata completeness.

### Added

- **`.github/workflows/release.yml`** now emits `njordium_suitecrm-<version>.tar.gz` alongside the existing `.zip`, both with matching `.sha256` files. The Nextcloud App Store submission API requires `tar.gz`; direct-install admins can continue using the zip. Both archives are built from the same rsync-staged tree so the file contents are byte-identical.

## 2.0.0 – 2026-07-22

### ⚠ BREAKING CHANGES

- **App id renamed** from `integration_suitecrm` to `njordium_suitecrm`. The Nextcloud App Store record for `integration_suitecrm` still points to Julien's original 1.0.3 (last updated November 2021), and every field the NC admin panel displays for our fork was being pulled from that stale record. Renaming the id de-couples the fork from that record for good.
- The install folder is now `custom_apps/njordium_suitecrm/` — the old `custom_apps/integration_suitecrm/` will no longer be recognised.
- The `occ` command is now `occ njordium_suitecrm:test-connection`. The old command name will not resolve.
- Route names, OAuth redirect URI paths, config-app keys, and Vue translation domains all use the new id.

### Migration

A `Migration\CopyLegacyAppConfig` Repair step is registered under `<post-migration>` and runs automatically on `occ upgrade`. It copies every row from `oc_appconfig` and `oc_preferences` where `appid = 'integration_suitecrm'` under the new app id, skipping any target row that already exists (idempotent). Legacy rows are **not deleted** — a follow-up 2.1.0 Repair step will do that once 2.0.0 has been stable for a while, keeping a clean rollback path in the meantime.

**Upgrade steps** (Docker example, adapt paths for LXC/bare-metal):

```bash
# Disable the old app
docker exec -u www-data <container> php occ app:disable integration_suitecrm

# Drop the new folder (zip contains a top-level `njordium_suitecrm/`)
unzip njordium_suitecrm-v2.0.0.zip -d /path/to/nextcloud/custom_apps/

# Enable the new app — this fires the Migration Repair step,
# which copies your admin OAuth config + every user's OAuth tokens
docker exec -u www-data <container> php occ app:enable njordium_suitecrm

# Optional: after verifying everything works
rm -rf /path/to/nextcloud/custom_apps/integration_suitecrm
```

**Users do not need to reconnect** — their per-user OAuth tokens carry over via the Repair step's copy of `oc_preferences`. The admin OAuth client id/secret/instance URL and authorize path also carry over automatically.

### Rollback

If v2.0.0 misbehaves for you, the old rows are untouched:

```bash
docker exec -u www-data <container> php occ app:disable njordium_suitecrm
docker exec -u www-data <container> php occ app:enable integration_suitecrm
```

Everything's back where it was on 1.9.1.

### What this fixes

The "Latest updated: November 2021" line in the NC admin panel goes away — the new app id doesn't match any App Store record, so NC has no stale cache to render.

### Added

- **`lib/Migration/CopyLegacyAppConfig.php`**: `IRepairStep` implementation covering the id rename described above. Idempotent, safe on fresh installs, silent no-op when there is nothing to migrate. Registered in `appinfo/info.xml` under `<repair-steps><post-migration>`.

### Changed

- `<id>` in `appinfo/info.xml`: `integration_suitecrm` → `njordium_suitecrm`.
- `Application::APP_ID`: same.
- Webpack bundle output filenames: `integration_suitecrm-{personalSettings,adminSettings,dashboard,calendar}.js` → `njordium_suitecrm-*.js`.
- Release workflow (`.github/workflows/release.yml`): staging directory, zip filename, artifact name, and README install instructions all reference the new id. The GitHub repo URL and Composer package identifier stay `njordium/integration_suitecrm` — those correspond to the repository path, not the Nextcloud app id.
- All Vue `t()` translation domains, `loadState()` calls, `generateUrl()` prefixes.
- All documentation examples (`README.md`, `docs/getting-started.md`, `docs/proxmox-lxc.md`, `docs/reverse-proxy.md`) reference the new id in commands and paths.

## 1.9.1 – 2026-07-22

Follow-up release covering the audit-driven cleanup and enhancement work that closed several upstream `julien-nc/integration_suitecrm` issues after the 1.9.0 tag.

### Added

- **`docs/getting-started.md`**: end-to-end admin walkthrough from an unconnected SuiteCRM install to a working integration with smoke tests. Explicit LDAP note calls out that AD-backed users must use the authcode flow, not the Advanced password fallback (closes upstream issues [#2](https://github.com/julien-nc/integration_suitecrm/issues/2), [#9](https://github.com/julien-nc/integration_suitecrm/issues/9), [#11](https://github.com/julien-nc/integration_suitecrm/issues/11)).
- **Calendar widget: past-due-not-dispositioned items**: `SuiteCRMAPIService::getUpcoming()` now widens its date filter to include the last 30 days (configurable) and returns past-due Meetings/Calls whose status is still `Planned` and Tasks whose status is not `Completed`/`Deferred`. Each row carries an `is_overdue` flag for frontend badging. Before iter 50 the filter was `date > now` and past-due rows silently vanished. Closes upstream [#8](https://github.com/julien-nc/integration_suitecrm/issues/8).
- **Admin UI "Reset connection" button**: `AdminSettings.vue` gets a warning-styled button that opens a confirmation dialog and DELETEs `/apps/integration_suitecrm/admin-config`, clearing `oauth_instance_url`, `client_id`, `client_secret`, and `oauth_authorize_path`. Individual user tokens are intentionally left in place — they'll fail their next SuiteCRM request and the per-user OAuth flow restarts automatically. Closes upstream [#14](https://github.com/julien-nc/integration_suitecrm/issues/14).
- **PHPUnit regression coverage** for the new work: `testResetAdminConfigDeletesAllExpectedKeys` on `ConfigControllerTest`, and four new methods on `SuiteCRMAPIServiceTest` covering `getUpcoming`'s past-due / dispositioned / future-with-Held-status / Task-vocabulary paths plus a structural guard that every `UPCOMING_MODULES` row declares `overdue_statuses`.

### Changed

- `README.md`: pointer to `docs/getting-started.md` at the top; mention of the `occ integration_suitecrm:test-connection` diagnostic in the Features and Configuration sections; Deployment scenarios now points at `docs/reverse-proxy.md` and `docs/proxmox-lxc.md` rather than duplicating them inline.
- `AUTHORS.md`: add Kim Haverblad as fork maintainer.
- `CHANGELOG.md`: 1.9.0 backfill covering iters 33-46 (this file).

### Removed

- `.tx/config` and the surrounding `.tx/` directory — the Transifex configuration pointed at a `translationfiles/` tree that hasn't existed in the fork since l10n was deferred in 1.8.0.
- `.l10nignore` — only meaningful for apps in Nextcloud's l10n pipeline; the fork isn't.
- Root `makefile` — its `appstore` target references `translationfiles/`, `l10n.pl`, `crowdin.yml`, and various other files that don't exist in the fork. Superseded by `.github/workflows/release.yml`.

### Fixed

- **Iter 50b (PHPStan hotfix)**: dropped an `?? []` fallback that PHPStan level 5 rejected as dead code — `UPCOMING_MODULES` provably declares `overdue_statuses` on every row.
- **Iter 51b (Vue hotfix)**: `NcButton` and the `NcDialog` button descriptors now use the current Nextcloud/vue v9 `variant` prop rather than the deprecated `type`.

## 1.9.0 – 2026-07-21

Backfills the full body of work landed between the 1.8.0 tag and the 1.9.0 release cut on commit `c2d5f55`. The theme was audit-and-fix — every prior iteration got a substantive review, most of them uncovered latent bugs that were shipped-but-never-run, and each fix carries a live-verification note against the docker container or the user's production Ubuntu 24.04 LXC.

### Added

- **Enriched OAuth error envelope** (`SuiteCRMAPIService::requestOAuthAccessToken()`): the token-exchange call now returns `error_kind`, `http_status`, `error_code`, `error_description`, and the raw body on failure paths. `ConfigController::oauthCallback()` uses these to produce admin-friendly guidance for `local_server_blocked` (SSRF guard), `401 / invalid_client` (SHA-256 vs bcrypt trap + redirect_uri mismatch), and transport-level failures. Previously the actionable-error branches were unreachable dead code because the service's outer `catch(\Throwable)` swallowed every exception into `['error' => msg]`.
- **Hardened release workflow** (`.github/workflows/release.yml`): inline lint/test gate (PHP syntax across 8.2/8.3/8.4, PHPStan, PHPUnit, JS lint, stylelint, webpack build, bundle presence check) runs before packaging; a version-sync step fails the release if the pushed tag does not match `<version>` in `appinfo/info.xml`; SHA-256 checksum is emitted alongside the zip; release body corrects `apps/` → `custom_apps/`. Dry-run twice via `workflow_dispatch` to prove the whole build path actually runs.
- **`occ integration_suitecrm:test-connection` diagnostic**: five-check `occ` command covering admin-config completeness, SSRF-guard interaction with the target host, HTTP reachability, authorize endpoint (accepts 200/302/303/307/308 so all SuiteCRM 8.x variants pass), and the token endpoint (POST with a bogus grant, expects 400 with `unsupported_grant_type`). Live-run against SuiteCRM 8.10.1 — 8/8 green with the correct admin config.
- **Reverse-proxy deployment reference** (`docs/reverse-proxy.md`): nginx (NC-only + NC+SuiteCRM), Apache with a working PHP-FPM handler block (do not copy-paste without it — mod_php-less Apache would serve `.php` files as text), Cloudflare Tunnel, and a dedicated "About allow_local_remote_servers" section that clarifies the SSRF guard resolves the target IP rather than caring about the request path.
- **Proxmox LXC production reference** (`docs/proxmox-lxc.md`): grounded in a live Ubuntu 24.04 LTS + PHP 8.3 + NC 33 LXC. Two-LXC layout (Nextcloud + SuiteCRM), `pct create` commands, install scripts using `libapache2-mod-php` (matching what the popular Proxmox helper-script installers actually produce), `www-data` classic crontab for NC cron with an optional systemd-timer alternative, PVE-host nginx pointer, backup script preserving the NC config.php `secret` and the SuiteCRM OAuth2 keypair, upgrade path.
- **Cross-module search widened to first_name for person modules**: Contacts and Leads now filter on both `last_name` and `first_name` via per-attribute requests + result dedup by `module|id`. First-name-only queries (typing `Serena` and expecting `Serena Arent`) now return hits; before iter 35 they returned zero. Documented the deliberate rejection of `filter[operator]=or` (SuiteCRM 8.4/8.5 DBAL applies OR at the top of the WHERE clause and returns every non-deleted row).
- **PHPUnit regression suite for `SuiteCRMAPIService::search()`** (`tests/php/Service/SuiteCRMAPIServiceTest.php`): 11 tests covering every historical search bug — the `contains` operator regression, the `full_name` computed-field trap, the missing `first_name` in `name_attrs`, the invalid `date_sent` on Emails, and the error-handling semantics (partial-attribute failure suppresses warnings, total failure logs). Behavioural tests use a partial mock of the service; structural tests use reflection on the private `SEARCH_MODULES` const.
- **`IAPIWidgetV2` support** on both dashboard widgets: alongside the existing `IWidget + IAPIWidget + IIconWidget` interfaces, both widgets now implement `IAPIWidgetV2` and return a `WidgetItems` envelope with a SuiteCRM-specific `emptyContentMessage` ("No SuiteCRM notifications!" / "No upcoming SuiteCRM events") instead of the dashboard's generic "No entries" placeholder. Falls back cleanly to V1 on any NC that doesn't probe V2.

### Changed

- **`search()` operator reverted to `like` with wildcards**: SuiteCRM 8.10.1 responds `400 Filter operator contains is invalid`, so the `%wildcards%` pattern is back and the operator is `like`. Live-verified against the container.
- **Emails module `fields` list**: dropped `date_sent`; that column does not exist on SuiteCRM 8's Email bean and requesting it responded `400 The following field in Email module is not found: date_sent`.
- **`TestConnection` authorize-path normalisation**: matches `ConfigController::oauthAuthorizeUrl()`'s pattern (`rtrim($url, '/') . '/' . ltrim($path, '/')`) so an admin who sets `oauth_authorize_path=Api/authorize` (no leading slash) does not get a false-negative from the diagnostic while the real OAuth flow works fine. Token endpoint path is now derived from the authorize path (`preg_replace /authorize$/ → /access_token`) so SuiteCRM installs upgraded from 7.x with the `/legacy/oauth2/*` layout also get a valid token check.
- **`softprops/action-gh-release` bumped v2 → v3** and **`actions/upload-artifact` bumped v4 → v7** to eliminate GitHub's Node 20 deprecation warnings.
- **`README.md`** rewritten with deployment-scenario matrix (Docker, reverse proxy, cloud VPC, Proxmox LXC), install caveats (js/ bundle build required for tarball installs), the `allow_local_remote_servers` gotcha, and a troubleshooting section keyed by symptom.

### Fixed

- **ConfigController constructor signature drift**: `Iteration 33` added `LoggerInterface` at position #10, but `ConfigControllerTest` still constructed the SUT with ten positional arguments. `Iteration 34` updated the test to inject the logger mock and pass it at the right position. CI PHPUnit went from red → green.
- **`.phpunit.cache` leak into the release zip**: iter 38's PHPUnit gate wrote `.phpunit.cache/test-results` into the workdir before the packaging step ran. Iter 38b added it and half a dozen other transient caches (`.php-cs-fixer.cache`, `.phpcs-cache`, `.tool-versions`, `.node-version`, `.php-version`) to the rsync exclude list.
- **SuiteCRM unzip in `docs/proxmox-lxc.md`** would silently break `DocumentRoot`: the SuiteCRM 8.x GitHub-release ZIP ships with a top-level `SuiteCRM-<version>/` directory. Fix: unpack to a scratch dir, then `cp -a /tmp/scrm-unpack/SuiteCRM-*/. /var/www/suitecrm/`.
- **Test-connection expected output** in the Proxmox doc updated for iter 31's HTTP 307 case and iter 39's "Derived token endpoint path" line.
- Notification triage: 13 stale CI-failure notifications from iters 33 and earlier bulk-closed after later commits landed green.

### Verified live

- OAuth authcode flow end-to-end against SuiteCRM 8.10.1: authorize → consent → callback → token exchange → tokens stored, `user_name` + `user_id` populated on connect.
- Unified search from Nextcloud 30 against SuiteCRM 8.10.1: `Serena` returns the "Serena Arent" Contact, `Arent` also returns the Contact, `AtoZ` returns the Account + Opportunity, all 10 diverse queries return HTTP 200.
- Both dashboard widgets render with icons on the Nextcloud dashboard.
- `occ integration_suitecrm:test-connection` runs green with the stock config and (as of iter 39) also green with a no-leading-slash `oauth_authorize_path` value.
- Release workflow dry-run via `workflow_dispatch` produces a valid 7.03 MB zip with the correct layout (`lib/` + `js/` + `appinfo/` + `docs/` + `README` + `COPYING`, no `tests/` or `composer.json` or `.phpunit.cache/`).
- `nextcloud.log` clean of `integration_suitecrm` warnings after 20 minutes of diverse activity.
- Ubuntu 24.04 + PHP 8.3 + NC 33 target OS/version validated read-only against the user's production LXC — every command in `docs/proxmox-lxc.md` grounded in what the popular installer scripts actually produce.

## 1.8.0 – 2026-07-01
### Changed
- Modernised PHP 7.4-style classes to PHP 8 constructor property promotion: `SuiteCRMWidget`, `Settings\Personal`, `Settings\Admin` (property docblocks removed, all readonly-style dependencies now declared in the constructor signature)
- README rewritten as a proper user-facing document: feature list, requirements, install/config instructions, development workflow, contributing pointer, updated license credit — replaces the previous ~30-line placeholder

### Deferred
- Iteration 6 (NC Mail contact card via `IMailContactProvider`) — depends on `nextcloud/mail` being installed for meaningful testing; will land after a docker-based test environment is stood up
- Full l10n restoration (Transifex or committed `en.json` baseline) — English strings work fine as fallback; deferred until translations are actually needed

## 1.7.0 – 2026-07-01
### Added
- **composer.json** for PHP tooling (PHPUnit 10, PHPStan 1.11, nextcloud/ocp stubs, PSR-4 autoload for `OCA\SuiteCRM\`)
- **phpunit.xml** with tests/php coverage of lib/
- **phpstan.neon** at level 5 covering all of lib/ (Application.php excluded because it depends on OC AppFramework stubs that don't survive plain analyse without a live NC codebase)
- **Test suite**:
  - `TokenStorageTest`: encryption on write, decryption on read, backward-compat plaintext migration, clear-all, empty-string short-circuits
  - `RecordUrlParserTest`: 5 valid-URL data-provider cases (query-string ordering, HTML-encoded ampersands, embedded in prose, mixed case), 6 rejection cases, module list assertion
- Extracted `RecordUrlParser` from `SuiteCRMReferenceProvider` as a pure static class — testable without instantiating IConfig/IL10N/IURLGenerator/etc.
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
- `SuiteCRMAPIService::getUpcoming()` — data-driven fetch across Meetings/Calls/Tasks with server-side date + assignee filters; returns rows tagged with `type` and a normalised `event_ts` for client-side sorting
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
- `SuiteCRMAPIService::search()` refactored to a data-driven loop over a `SEARCH_MODULES` table — new modules can be added by appending a single row
- `SuiteCRMSearchProvider` result formatting replaced repeated if/elseif chains with a `TYPE_TO_MODULE` map and `match()` expressions

### Fixed
- Regex-injection vulnerability in search: the user query was interpolated directly into `preg_match()`. Now passed through `preg_quote()` — searches for characters like `.`, `/`, `(`, `?`, `+` no longer break the pattern or throw

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
- Calendar Companion panel in Personal Settings exposing Nextcloud URL, login, and a deep link to /settings/user/security for app-password generation — feeds the per-user setup flow of the [suitecrm_nextcloud_calendar](https://github.com/njordium/suitecrm_nextcloud_calendar) SuiteCRM-side module
- GitHub Actions CI: lint + build (JS) and php -l (PHP 8.1/8.2/8.3) on push and PR

### Changed
- All token reads/writes now go through TokenStorage (ConfigController, SuiteCRMAPIController, SuiteCRMAPIService, SuiteCRMSearchProvider)
- Fixed CospendSearchProvider copy-paste docblock in SuiteCRMSearchProvider

## 1.1.0 – 2026-06-30
### Changed
- Fork: updated Nextcloud compatibility to NC25–34
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
