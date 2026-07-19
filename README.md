# SuiteCRM integration for Nextcloud

> **Actively maintained fork** of [julien-nc/integration_suitecrm](https://github.com/julien-nc/integration_suitecrm) by Julien Veyssier.
> Updated for **Nextcloud 30–34** and **SuiteCRM 8.x**, migrated to Vue 3 / `@nextcloud/vue` v9, and extended with reference cards, smart picker, calendar widget, encrypted token storage, and a companion CalDAV sync module.

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
- **OAuth 2.0 authorization-code flow** (RFC 6749) is the primary connect path — the password grant is kept as a labelled "Advanced" fallback only

---

## Requirements

- Nextcloud **30 – 34**
- **SuiteCRM 8.x** with the v8 REST API enabled and OpenSSL keys generated (v7.x is no longer supported)
- PHP **8.2+**

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
2. Create an **OAuth2 Client** in SuiteCRM's "OAuth2 Clients and Tokens" admin section. A single client can be configured to accept both the authorization-code grant (recommended) and the password grant (fallback).
3. In Nextcloud, open **Settings → Administration → Connected accounts → SuiteCRM integration** and enter the SuiteCRM instance URL, client ID, and client secret.
4. **Redirect URI (for OAuth authorization-code flow):**
   add `<your-nextcloud-url>/apps/integration_suitecrm/oauth-callback` as an allowed redirect URI on the OAuth2 Client you created in step 2.
5. **Authorize endpoint path** (optional): SuiteCRM 8.x installs disagree on where the OAuth authorize endpoint sits. The default `/legacy/oauth2/authorize` works for fresh 8.x installs; installs upgraded from 7.x with the V8 API bolted on may need `/Api/authorize`. Override via the `oauth_authorize_path` admin setting if needed.

### Per user
Open **Settings → Personal → Connected accounts → SuiteCRM integration** and click **"Connect via SuiteCRM OAuth (recommended)"**. You will be redirected to your SuiteCRM instance to sign in and approve access; on approval you land back in Personal Settings connected.

If your SuiteCRM instance cannot complete the browser redirect back to Nextcloud, expand the **"Advanced: username + password fallback"** section and enter your SuiteCRM login and password (used once to obtain an OAuth token, not stored).

Then enable search and/or notifications.

For the calendar-sync companion module, use the "Calendar sync (SuiteCRM module)" section for the pre-filled values.

### Connect flow

- **Primary — OAuth 2.0 authorization code (recommended):** clicking "Connect via SuiteCRM OAuth" issues a state-bound authorize URL and redirects the browser to SuiteCRM's login/consent screen. On approval SuiteCRM redirects back to `/apps/integration_suitecrm/oauth-callback`, which exchanges the code for tokens and lands the user back in Personal Settings. Your SuiteCRM password is never sent to Nextcloud.
- **Fallback — password grant (Advanced):** available in the collapsible "Advanced" section for edge cases where a browser redirect back to Nextcloud is not viable (air-gapped setups, installs behind a redirect-blocking proxy, etc). The credentials are used once to obtain a token and never stored.

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

CI (`.github/workflows/lint.yml`) runs all of the above on every push and PR across PHP 8.2 / 8.3 / 8.4 and Node 20.

---

## Contributing

Issues and pull requests welcome at [njordium/integration_suitecrm](https://github.com/njordium/integration_suitecrm/issues).

---

## License

AGPL-3.0-or-later. See [COPYING](COPYING).

Original author: Julien Veyssier. Fork maintained by khnjrdm / [njordium](https://njordium.com).
