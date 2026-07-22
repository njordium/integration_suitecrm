# Getting started, SuiteCRM integration for Nextcloud

Zero-to-connected in about 15 minutes. This walkthrough assumes you already have:

- A running Nextcloud 30 – 34 installation you administer.
- A running SuiteCRM 8.x installation you administer (the fork does not support 7.x).
- Both instances reachable from your browser and from each other over HTTP(S).

If you don't have those yet, the two deployment references live at [`docs/proxmox-lxc.md`](proxmox-lxc.md) (Proxmox LXC, Ubuntu 24.04) and [`docs/reverse-proxy.md`](reverse-proxy.md) (nginx / Apache / Cloudflare Tunnel).

## 1. Install the app on Nextcloud

Pick one:

**App Store install (recommended):** Apps → Integration → search "SuiteCRM integration" → Install.

**Manual install from source:**

```bash
cd /var/www/nextcloud/custom_apps    # or apps/ depending on your layout
git clone https://github.com/njordium/integration_suitecrm.git
cd njordium_suitecrm
npm ci
npm run build
sudo -u www-data php /var/www/nextcloud/occ app:enable njordium_suitecrm
```

**Release-zip install:** grab `njordium_suitecrm-<version>.zip` from the [Releases page](https://github.com/njordium/integration_suitecrm/releases), extract into `custom_apps/`, enable the app. Zip already includes the built `js/` bundles, no `npm run build` needed.

Verify the app is enabled: **Settings → Administration → Overview** shows no complaints about `njordium_suitecrm`, and `Settings → Administration → Connected accounts` has a **SuiteCRM integration** section.

## 2. Prepare SuiteCRM's OAuth2 layer

The v8 REST API needs an OpenSSL keypair before it will issue tokens. On the SuiteCRM host:

```bash
cd /path/to/SuiteCRM/public/legacy/Api/V8/OAuth2   # legacy layout; adjust if you use the modern one
sudo -u www-data openssl genrsa -out private.key 2048
sudo -u www-data openssl rsa -in private.key -pubout -out public.key
chmod 600 private.key
chmod 644 public.key
```

If those files already exist and the API responds, you can skip this step.

## 3. Create the OAuth2 Client in SuiteCRM

In SuiteCRM's admin UI:

1. Admin → **OAuth2 Clients and Tokens** → **New OAuth2 Client**.
2. **Name:** anything, "Nextcloud integration" is fine.
3. **Client ID:** anything, this is what Nextcloud will send. Something like `nc-suitecrm-client` works.
4. **Secret:** click Generate, or type a strong random string. Note this value, you cannot recover it later; you'd have to reset and create a new client.
5. **Grant Type:** **Authorization Code** is the recommended choice. If your setup absolutely cannot complete a browser redirect back to Nextcloud (air-gapped or redirect-blocking proxy), pick **Password** instead, Nextcloud's fallback "Advanced" panel supports it.
6. **Redirect URL:** exactly `<your-nextcloud-public-url>/apps/njordium_suitecrm/oauth-callback`. **Byte-for-byte match matters**, `http` vs `https`, port number, trailing slash, all count. If your Nextcloud is `https://cloud.example.com`, this is `https://cloud.example.com/apps/njordium_suitecrm/oauth-callback`.

Save. Note the Client ID + Secret; you'll paste them into Nextcloud next.

> **The bcrypt vs SHA-256 trap.** SuiteCRM 8.10.x stores the OAuth2 client secret as raw `hash('sha256', $secret)`, **not** bcrypt. If you seeded via SQL with `password_hash()`, the client will 401 with `invalid_client` no matter what secret you send. Always create the client via the admin UI (which hashes correctly), not via direct SQL insert.

## 4. Configure Nextcloud

Open **Settings → Administration → Connected accounts → SuiteCRM integration** and fill in:

- **SuiteCRM instance URL:** the base URL of your SuiteCRM install. `https://crm.example.com`. No trailing slash needed but it doesn't hurt.
- **Client ID:** what you set in step 3.
- **Client Secret:** what you set in step 3. (The field is masked after save.)

The authorize endpoint path defaults to `/Api/authorize`, which is what stock SuiteCRM 8.10.x exposes. If your install is upgraded from 7.x, it may expose the legacy `/legacy/oauth2/authorize` path instead, same field, editable in the admin UI.

Save.

## 5. Diagnostics, verify the wiring before any user tries to connect

The fork ships an `occ` command that walks every layer that has bitten users during setup:

```bash
sudo -u www-data php /var/www/nextcloud/occ njordium_suitecrm:test-connection
```

Expected output when the wiring is correct:

```
SuiteCRM integration, connection diagnostic

  ✓ Admin config: oauth_instance_url = https://crm.example.com
  ✓ Admin config: client_id = nc-suitecrm-client
  ✓ Admin config: client_secret is set (hidden)
  ✓ Admin config: oauth_authorize_path = /Api/authorize
  ✓ Derived token endpoint path: /Api/access_token
  ✓ SSRF guard: host "crm.example.com" is public, no whitelist needed
  ✓ HTTP reachability: https://crm.example.com → HTTP 200
  ✓ Authorize endpoint (/Api/authorize): HTTP 307 (OK)
  ✓ Token endpoint (/Api/access_token): HTTP 400 with error="unsupported_grant_type" (OK)

All checks passed. Users should be able to complete the OAuth flow.
```

Common non-OK signals and what they mean:

| Message | Meaning |
|---|---|
| `Admin config: oauth_instance_url is empty` | Nothing set in step 4. |
| `SSRF guard: host "..." looks like an RFC-1918 / loopback address but allow_local_remote_servers is FALSE` | Nextcloud's SSRF guard refuses outbound to private-range IPs. If your SuiteCRM is on the LAN or same Docker host, run `sudo -u www-data php occ config:system:set allow_local_remote_servers --value=true --type=boolean` and retry. |
| `HTTP reachability: cannot connect to <url>` | DNS, firewall, or SuiteCRM container not running. Confirm with `curl -v <url>` from the Nextcloud host. |
| `Authorize endpoint (...): HTTP 404` | Wrong path. Try `/legacy/oauth2/authorize`, see step 4. |
| `Token endpoint (...): HTTP 404` | SuiteCRM's OAuth2 keypair isn't generated. Redo step 2. |

The command is safe to run, it doesn't touch stored user tokens.

## 6. Per-user connect

The admin config in step 4 only unlocks the plumbing. Each user still connects their own SuiteCRM identity.

For each user who wants to use the integration:

1. Open **Settings → Personal → Connected accounts → SuiteCRM integration**.
2. Click **Connect via SuiteCRM OAuth (recommended)**.
3. The browser redirects to your SuiteCRM instance, logs in (or reuses an existing session), and presents a consent screen.
4. Approve → redirected back to Personal Settings, now shown as connected.

Optionally toggle **Enable search integration** (adds SuiteCRM as a source in Nextcloud's unified search) and **Enable notifications** (SuiteCRM reminder pop-ups land in Nextcloud's notification tray).

If your SuiteCRM install truly cannot complete a browser redirect back, some air-gapped or redirect-blocking-proxy setups, expand **"Advanced: username + password fallback"** and enter your SuiteCRM login + password. Nextcloud uses them once to obtain an OAuth token and does not store them.

> **LDAP / Active Directory users must use the authcode flow.** The Advanced password fallback issues a `grant_type=password` request against SuiteCRM's OAuth2 layer, and SuiteCRM's password grant does not consult the LDAP module: you'll get "The password is invalid" no matter what credentials you paste in. Use the primary "Connect via SuiteCRM OAuth" button and let SuiteCRM's own login screen route the credentials through LDAP as normal. (This is what upstream [issue #9](https://github.com/julien-nc/integration_suitecrm/issues/9) surfaced; the move to authcode-first is the fix.)

## 7. Smoke tests

Confirm each of the four surfaces works end-to-end.

**Unified search:** click the magnifier icon in the top-right of Nextcloud. Type a substring of any Contact or Account name in your SuiteCRM. Results should appear under "SuiteCRM" within a second. If it stays empty for a name you know exists, see [Troubleshooting → Unified search returns 0 hits](../README.md#troubleshooting) in the main README.

**Dashboard widgets:** open the Nextcloud dashboard (top-left grid icon → Dashboard). "SuiteCRM events" and "SuiteCRM calendar" widgets appear. If the connected user has upcoming Meetings, Calls, or Tasks assigned to them in SuiteCRM, they show up here.

**Reference cards:** paste a link to any SuiteCRM record into a Talk chat or a Note, for instance `https://crm.example.com/index.php?module=Contacts&action=DetailView&record=abc-123-def`. The link should render as a rich preview card with the record's name.

**Notifications:** if the user has a SuiteCRM reminder scheduled to fire within the next few minutes, wait for it, a Nextcloud notification should appear.

## 8. When something goes wrong

The [README's Troubleshooting section](../README.md#troubleshooting) is keyed by symptom for every failure mode we've encountered in the wild, `invalid_client` from SuiteCRM, empty settings section (missing JS bundles), SSRF-guard blocks, reverse-proxy scheme mismatches, and HSTS-cache surprises.

If you hit something not covered there, `occ njordium_suitecrm:test-connection` from step 5 is the fastest first-line diagnostic. Its output tells you which layer is broken.

Filed issues welcome at [https://github.com/njordium/integration_suitecrm/issues](https://github.com/njordium/integration_suitecrm/issues), please include the `occ test-connection` output.

## Related documents

- [`docs/reverse-proxy.md`](reverse-proxy.md), nginx / Apache / Cloudflare Tunnel front-end configuration when Nextcloud lives behind a public reverse proxy.
- [`docs/proxmox-lxc.md`](proxmox-lxc.md), full production reference deploying Nextcloud + SuiteCRM 8 as two Ubuntu 24.04 LXCs on a Proxmox host, verified against a live Nextcloud 33 install.
