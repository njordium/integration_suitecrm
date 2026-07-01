# Change Log
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

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
