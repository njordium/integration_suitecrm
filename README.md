# SuiteCRM integration for Nextcloud

> **Actively maintained fork** of [julien-nc/integration_suitecrm](https://github.com/julien-nc/integration_suitecrm) by Julien Veyssier.
> Updated for **Nextcloud 25–34** and **SuiteCRM 8.x**, migrated to Vue 3 / `@nextcloud/vue` v9, and extended with reference cards, smart picker, calendar widget, encrypted token storage, and a companion CalDAV sync module.

Interact with your SuiteCRM instance from inside Nextcloud — search records, see upcoming events on your dashboard, get notified about meeting reminders, and paste CRM links into Talk/Notes for rich preview cards.

---

## Features

### Unified search
Search across your SuiteCRM data from Nextcloud's global search bar. Supports:
**Contacts · Accounts · Leads · Opportunities · Cases · Meetings · Tasks · Emails**

### Dashboard widgets
Two home-dashboard widgets:
- **SuiteCRM events** — reminders for upcoming Calls/Meetings that need your attention
- **SuiteCRM calendar** — chronological list of assigned Meetings, Calls, and Tasks in the next 7 days

### Reference cards & smart picker
Paste any SuiteCRM record URL (e.g. `.../index.php?module=Contacts&record=abc-123`) into Talk messages, Notes, Deck cards, or Files comments and it renders inline as a rich preview card.

Type `@` in any Nextcloud text field to open the smart picker and search SuiteCRM directly.

### Notifications
Meeting and Call reminders from SuiteCRM show up in Nextcloud's notification tray.

### Calendar sync (companion module)
Pair with the [njordium/suitecrm_nextcloud_calendar](https://github.com/njordium/suitecrm_nextcloud_calendar) SuiteCRM module for two-way calendar sync via CalDAV — SuiteCRM Meetings/Calls appear in Nextcloud Calendar and vice-versa, with double-booking detection and Nextcloud Appointments booking → SuiteCRM Meeting conversion.

The Personal Settings panel includes a Calendar Companion section that streamlines the setup: shows your Nextcloud URL and username with one-click copy, plus a link to generate an app password.

### Security
- OAuth2 access + refresh tokens are encrypted at rest using Nextcloud's `ICrypto` service
- Tokens migrated transparently from plaintext (installs upgraded from ≤ 1.1.x)

---

## Requirements

- Nextcloud **25 – 34**
- SuiteCRM **8.x** with the v8 REST API enabled and OpenSSL keys generated
- PHP **8.1+**

---

## Installation

### From the Nextcloud App Store
Search for "SuiteCRM integration" in Apps → Integration.

### Manual install
```bash
cd /var/www/nextcloud/apps
git clone https://github.com/njordium/integration_suitecrm.git
cd integration_suitecrm
npm ci
npm run build
```
Then enable it in **Apps → Integration → SuiteCRM integration**.

---

## Configuration

### Admin
1. In SuiteCRM, generate OpenSSL private + public keys ([docs](https://docs.suitecrm.com/developer/api/developer-setup-guide/json-api/#_generate_private_and_public_key_for_oauth2)).
2. Create a **New Password Client** in the "OAuth2 Clients and Tokens" admin section.
3. In Nextcloud, open **Settings → Administration → Connected accounts → SuiteCRM integration** and enter the SuiteCRM instance URL, client ID, and client secret.

### Per user
Open **Settings → Personal → Connected accounts → SuiteCRM integration**, enter your SuiteCRM login and password (used once to obtain an OAuth token, not stored), then enable search and/or notifications.

For the calendar-sync companion module, use the "Calendar sync (SuiteCRM module)" section for the pre-filled values.

---

## Development

```bash
# JS/Vue
npm ci
npm run watch       # dev build with file watching
npm run lint        # ESLint 9 flat config
npm run stylelint
npm run build       # production build

# PHP
composer install
vendor/bin/phpunit  # unit tests
vendor/bin/phpstan analyse -c phpstan.neon
```

CI (`.github/workflows/lint.yml`) runs all of the above on every push and PR across PHP 8.1 / 8.2 / 8.3 and Node 20.

---

## Contributing

Issues and pull requests welcome at [njordium/integration_suitecrm](https://github.com/njordium/integration_suitecrm/issues).

---

## License

AGPL-3.0-or-later. See [COPYING](COPYING).

Original author: Julien Veyssier. Fork maintained by khnjrdm / [njordium](https://njordium.com).
