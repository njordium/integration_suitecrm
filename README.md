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

> **⚠️ If you install by grabbing the source tarball** (`/archive/refs/heads/master.tar.gz`), the compiled `js/` bundles are **not** included (they're `.gitignore`d and produced by CI). You MUST run `npm ci && npm run build` on the host before enabling the app, otherwise the personal-settings section renders empty with no console errors. See [Troubleshooting → empty settings section](#troubleshooting).

---

## Configuration

### Admin
1. In SuiteCRM, generate OpenSSL private + public keys ([docs](https://docs.suitecrm.com/developer/api/developer-setup-guide/json-api/#_generate_private_and_public_key_for_oauth2)).
2. Create an **OAuth2 Client** in SuiteCRM's "OAuth2 Clients and Tokens" admin section. A single client can be configured to accept both the authorization-code grant (recommended) and the password grant (fallback).
3. In Nextcloud, open **Settings → Administration → Connected accounts → SuiteCRM integration** and enter the SuiteCRM instance URL, client ID, and client secret.
4. **Redirect URI (for OAuth authorization-code flow):**
   add `<your-nextcloud-url>/apps/integration_suitecrm/oauth-callback` as an allowed redirect URI on the OAuth2 Client you created in step 2.
5. **Authorize endpoint path** (optional): the default `/Api/authorize` is what SuiteCRM 8.10.x exposes (verified live against a stock install). Older 8.x builds and upgraded-from-7.x installs may need `/legacy/oauth2/authorize` instead. As of **v1.9.1** the same field is editable in the admin OAuth settings UI (see step 3) — no CLI required. To set it via the command line instead:
   ```bash
   sudo -u www-data php occ config:app:set integration_suitecrm oauth_authorize_path --value="/Api/authorize"
   ```

### If SuiteCRM is hosted on your LAN or same host as Nextcloud

Nextcloud refuses outbound HTTP to RFC-1918 addresses (10/8, 172.16/12, 192.168/16) and loopback by default, as an SSRF guard. If your SuiteCRM URL is on any of those ranges, the OAuth token exchange will fail with:

```
OAuth access token could not be obtained: Host "<address>" violates local access rules
```

Fix by whitelisting local outbound targets:

```bash
sudo -u www-data php occ config:system:set allow_local_remote_servers --value=true --type=boolean
```

This applies for Docker-on-same-host setups, Proxmox LXC deployments where NC and SuiteCRM are separate containers on the PVE bridge, and any cloud VPC deployment where both apps are on internal-only IPs.

### Per user
Open **Settings → Personal → Connected accounts → SuiteCRM integration** and click **"Connect via SuiteCRM OAuth (recommended)"**. You will be redirected to your SuiteCRM instance to sign in and approve access; on approval you land back in Personal Settings connected.

If your SuiteCRM instance cannot complete the browser redirect back to Nextcloud, expand the **"Advanced: username + password fallback"** section and enter your SuiteCRM login and password (used once to obtain an OAuth token, not stored).

Then enable search and/or notifications.

For the calendar-sync companion module, use the "Calendar sync (SuiteCRM module)" section for the pre-filled values.

### Connect flow

- **Primary — OAuth 2.0 authorization code (recommended):** clicking "Connect via SuiteCRM OAuth" issues a state-bound authorize URL and redirects the browser to SuiteCRM's login/consent screen. On approval SuiteCRM redirects back to `/apps/integration_suitecrm/oauth-callback`, which exchanges the code for tokens and lands the user back in Personal Settings. Your SuiteCRM password is never sent to Nextcloud.
- **Fallback — password grant (Advanced):** available in the collapsible "Advanced" section for edge cases where a browser redirect back to Nextcloud is not viable (air-gapped setups, installs behind a redirect-blocking proxy, etc). The credentials are used once to obtain a token and never stored.

---

## Deployment scenarios

### Local Docker (dev / test)
Both Nextcloud and SuiteCRM in containers on the same Docker host, talking via the docker bridge. Works out of the box **once you set `allow_local_remote_servers=true`** (the bridge addresses are RFC-1918). The redirect URI in the SuiteCRM OAuth client must match the URL your browser uses to reach Nextcloud (typically `http://<host-ip>:<port>`).

### Behind a reverse proxy (Traefik, nginx, Apache, Cloudflare Tunnel)
Nextcloud must know its public URL for OAuth callback generation. Set these in `config.php`:

```php
'overwriteprotocol' => 'https',
'overwritehost' => 'cloud.example.com',
'overwritecondaddr' => '^10\.0\.0\.5$',  // your reverse proxy's IP
'trusted_proxies' => ['10.0.0.5'],
```

The redirect URI you register in SuiteCRM must be the **public** URL byte-for-byte:
`https://cloud.example.com/apps/integration_suitecrm/oauth-callback`

One wrong scheme (`http` vs `https`) or missing port and SuiteCRM rejects with `invalid_client`. If both NC and SuiteCRM are on the private side of the same reverse proxy, `allow_local_remote_servers=true` still applies.

### Cloud (AWS VPC, Azure VNet, GCP VPC)
If Nextcloud and SuiteCRM are both cloud-internal (VPC-private IPs), enable `allow_local_remote_servers`. If SuiteCRM is public-facing (has a real DNS name reachable from the internet), no local-access change needed. For managed database backends (RDS, Cloud SQL, Azure Database), Nextcloud's connection is unchanged; only the SuiteCRM URL matters for this integration.

Preserve the `secret` value in Nextcloud's `config.php` across restores — stored OAuth tokens are encrypted with it, and a mismatch will invalidate every user's connection.

### Proxmox LXC (production reference)
Two LXCs on the same PVE host: one for Nextcloud (Apache + PHP-FPM + MariaDB), one for SuiteCRM 8 (Apache + PHP + MariaDB). They see each other on the PVE bridge subnet (`10.10.10.x` typically) → set `allow_local_remote_servers=true` on the Nextcloud side. Run nginx as a reverse proxy on the PVE host itself for external HTTPS termination; the `overwriteprotocol`/`overwritehost`/`trusted_proxies` config above applies. Systemd handles NC's cron via `nextcloud-cron.timer`; the SuiteCRM LXC uses its own crontab for the SuiteCRM scheduler. Regular backups should include NC's `config.php` (for the `secret`), the NC data directory, and both database dumps.

---

## Troubleshooting

Keyed by symptom.

### `OAuth access token could not be obtained: Host "<addr>" violates local access rules`
Nextcloud's SSRF guard is blocking outbound HTTP to your SuiteCRM's LAN IP. Fix:
```bash
sudo -u www-data php occ config:system:set allow_local_remote_servers --value=true --type=boolean
```

### `invalid_client / Client authentication failed` on the callback
SuiteCRM rejected the client credentials. Two common causes:
1. **Redirect URI mismatch (byte-for-byte).** Compare the URL the browser sent (visible in the address bar during the redirect back) against what's stored in SuiteCRM's OAuth2 Client → `redirect_url` column. Scheme (`http` vs `https`), port, trailing slash — any difference triggers this.
2. **Seeded via SQL with the wrong hash algorithm.** SuiteCRM 8.10.x stores the OAuth2 client secret as raw `hash('sha256', $secret)`, **not** bcrypt (`password_hash`). If you seeded via SQL with `password_hash()` or a bcrypt-style hash, verify:
   ```sql
   SELECT id, secret FROM oauth2clients WHERE id = 'your-client-id';
   ```
   A 64-hex-char value is SHA-256; anything starting with `$2y$` is bcrypt and won't match. The safest fix is to delete the row and recreate the client via SuiteCRM's admin UI (`http://<suitecrm>/#/oauth2-clients/index`), which uses the correct algorithm transparently.

### Personal-settings SuiteCRM section renders empty (no errors in console)
The `js/` bundles are missing. Happens when installing from the source tarball (`/archive/refs/heads/master.tar.gz`) instead of from a proper release or app-store install. On the host:
```bash
cd /path/to/apps/integration_suitecrm
npm ci
npm run build
```
Then reload the settings page.

### `HTTPS 500` on the callback URL immediately after "Continue" in SuiteCRM installer
SuiteCRM 8's Symfony bootstrap requires the `.env` file (not just process env vars). If missing:
```bash
# SuiteCRM ships a `.env` template — restore it if you accidentally overwrote:
cd /path/to/SuiteCRM
git checkout .env    # or restore from the release zip
# Put your DB URL + APP_SECRET in .env.local (not .env)
```

Then clear the Symfony container cache — it bakes in the old env vars:
```bash
rm -rf /path/to/SuiteCRM/cache/prod/*
chown -R www-data:www-data /path/to/SuiteCRM/cache
```

### Unified search returns 0 hits despite matching data
Two possibilities:
1. **Running v1.9.0 or earlier?** Iteration 21 shipped a search regression (`contains` operator not supported by SuiteCRM 8.10.x). Upgrade to **v1.9.2+** which uses `like` with `%wildcards%`. Live-verified against SuiteCRM 8.10.1.
2. **Searching by first name?** By design the app filters on `last_name` (Contacts/Leads) and `name` (other modules) because SuiteCRM 8's JSON:API rejects filters on computed fields like `full_name`. First-name-only search doesn't match. Follow-up work planned.

### Browser sends HTTPS to my HTTP-only NC port (400 with `\x16\x03\x01` in access log)
Stale HSTS cache from a previous HTTPS-serving app on the same host:port (e.g. NC AIO on 8443). Clear HSTS for that hostname in your browser (`chrome://net-internals/#hsts` → "Delete domain security policies") or use a different hostname via `/etc/hosts`.

### Reverse proxy: OAuth callback lands on wrong scheme
`overwriteprotocol` isn't set or `overwritecondaddr` doesn't match your proxy's source IP. Test:
```bash
sudo -u www-data php occ config:system:get overwriteprotocol
sudo -u www-data php occ config:system:get overwritehost
sudo -u www-data php occ config:system:get overwritecondaddr
sudo -u www-data php occ config:system:get trusted_proxies
```
All four typically need to be set together for proxy-aware URL generation.

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
