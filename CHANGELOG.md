# Change Log
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

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
