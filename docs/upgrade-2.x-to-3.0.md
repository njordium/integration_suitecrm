# Upgrading from 2.x to 3.0.0

Version 3.0.0 renames the Nextcloud app id back from `njordium_suitecrm` to `integration_suitecrm`. This is the only breaking change in the release. Every setting on your existing 2.x install — admin OAuth config (the SuiteCRM instance URL, the client id/secret, the authorize path), every per-user OAuth token, every widget preference — carries across automatically via a Repair step that runs on `occ upgrade`. Users do not need to re-authorise SuiteCRM.

The rename exists because Julien Veyssier transferred ownership of the original `integration_suitecrm` App Store record to this fork in July 2026. The 2.0.0 rename to `njordium_suitecrm` was a pragmatic workaround for a stale record we no longer controlled; that record is now ours, so 3.0.0 restores the canonical id. Julien's ~200 existing 1.x installs get updates seamlessly from the App Store from 3.0.0 onwards.

## Prerequisites

- A working 2.x install (any 2.0 through 2.6 patch level is fine, the migration is version-agnostic within the 2.x line).
- Shell access to the Nextcloud host and permission to run `occ` as `www-data`.
- The `integration_suitecrm-v3.0.0.tar.gz` (or `.zip`) release asset and its `.sha256`.

## Migration steps

Examples are for a docker-compose deployment where the Nextcloud container is `nextcloud-nextcloud-1` and `custom_apps/` is bind-mounted at `/opt/nextcloud/data/nc/custom_apps/`. Adapt paths for LXC, bare metal, or NC AIO.

### 1. Verify the checksum

```bash
cd /tmp
curl -LO https://github.com/njordium/integration_suitecrm/releases/download/v3.0.0/integration_suitecrm-v3.0.0.tar.gz
curl -LO https://github.com/njordium/integration_suitecrm/releases/download/v3.0.0/integration_suitecrm-v3.0.0.tar.gz.sha256
sha256sum -c integration_suitecrm-v3.0.0.tar.gz.sha256
# Expected: integration_suitecrm-v3.0.0.tar.gz: OK
```

### 2. Disable the old app

```bash
docker exec -u www-data nextcloud-nextcloud-1 php occ app:disable njordium_suitecrm
```

The database rows keyed under `appid = 'njordium_suitecrm'` remain untouched. This is deliberate so a rollback (see below) can flip everything back with two commands.

### 3. Extract v3.0.0 into `custom_apps/`

```bash
tar -xzf /tmp/integration_suitecrm-v3.0.0.tar.gz -C /opt/nextcloud/data/nc/custom_apps/
chown -R 33:33 /opt/nextcloud/data/nc/custom_apps/integration_suitecrm
```

The tarball contains a top-level `integration_suitecrm/` directory that matches the restored app id — required for NC to load the app.

### 4. Enable the new app

```bash
docker exec -u www-data nextcloud-nextcloud-1 php occ app:enable integration_suitecrm
```

Enabling fires the `CopyLegacyAppConfig` Repair step. It emits a summary line to `occ` output such as:

```
Migrated 4 admin config key(s) and 7 user preference row(s) from "njordium_suitecrm" to "integration_suitecrm".
```

Zero rows migrated is fine on a fresh install; the Repair step is idempotent and can be re-run.

### 5. Update the SuiteCRM OAuth2 Client Redirect URL

**Manual step, cannot be automated.** SuiteCRM stores the OAuth2 client's redirect URL as a byte-for-byte string; the app id in the URL just changed, so SuiteCRM's stored value no longer matches what our OAuth flow sends. Sign in to SuiteCRM as admin, open **Admin panel → OAuth2 Clients and Tokens → OAuth2 Clients**, click your Nextcloud-connected client, and update its **Redirect URL** from:

```
https://cloud.example.com/apps/njordium_suitecrm/oauth-callback
```

to:

```
https://cloud.example.com/apps/integration_suitecrm/oauth-callback
```

(Substitute your Nextcloud URL. If you serve via a reverse proxy with a path prefix, include the prefix; if your Nextcloud has pretty URLs disabled, the path segment `/index.php/` sits between the host and `/apps/` — copy exactly what Nextcloud renders as absolute in its own URL bar.)

Save. Existing per-user tokens carried across in step 4 remain valid; only new connect/refresh flows use the redirect URL, so the update takes effect on the next OAuth handshake.

### 6. Verify the upgrade

```bash
docker exec -u www-data nextcloud-nextcloud-1 php occ app:list | grep -E 'integration_suitecrm|njordium_suitecrm'
```

Expected: `- integration_suitecrm: 3.0.0` in the Enabled block; `njordium_suitecrm` in the Disabled block (or absent entirely if you dropped the old app directory).

Then on the Nextcloud web UI:

- **Personal Settings → Connected accounts → SuiteCRM integration**: should show the old "Connected as …" state without any prompt to reconnect.
- **Dashboard**: enabled widgets continue rendering data.
- **Global floating action button**: opens the Quick Actions menu; keyboard shortcut `Cmd/Ctrl+Shift+K` still works.
- **`occ integration_suitecrm:test-connection`** (the diagnostic renamed from the 2.x `njordium_suitecrm:test-connection`) runs end-to-end.

## Rollback

If 3.0.0 misbehaves, rollback to 2.6.0 is safe because the Repair step is copy-only, not move — every `njordium_suitecrm` row is still in the database:

```bash
docker exec -u www-data nextcloud-nextcloud-1 php occ app:disable integration_suitecrm
docker exec -u www-data nextcloud-nextcloud-1 php occ app:enable njordium_suitecrm
```

If you also want to remove the 3.0.0 directory (optional, the app is disabled so it's inert either way):

```bash
rm -rf /opt/nextcloud/data/nc/custom_apps/integration_suitecrm
```

Report the misbehaviour on the issue tracker; happy to iterate.

## Post-upgrade housekeeping

Once 3.0.0 has been stable in your install for a while, the legacy `njordium_suitecrm` rows can be dropped to reclaim space (usually a few kilobytes; not urgent). A follow-up release will ship a Repair step that does the deletion; until then it's manual:

```bash
docker exec nextcloud-nextcloud-1 mariadb -unextcloud -p<db-password> nextcloud \
  -e "DELETE FROM oc_appconfig WHERE appid='njordium_suitecrm';
      DELETE FROM oc_preferences WHERE appid='njordium_suitecrm';"
```

Do this only after you're confident you won't need to roll back.

## Reference

- Sanity-check row counts before + after: `SELECT appid, COUNT(*) FROM oc_appconfig WHERE appid IN ('integration_suitecrm','njordium_suitecrm') GROUP BY appid; SELECT appid, COUNT(*) FROM oc_preferences WHERE appid IN ('integration_suitecrm','njordium_suitecrm') GROUP BY appid;`
- The App Store transfer thread: nextcloud/app-certificate-requests#1104 and nextcloud/app-certificate-requests#1114.
- Historical context: `docs/upgrade-1.9-to-2.0.md` documents the reverse migration (2.0.0) if you're upgrading from 1.9.x through 2.x to 3.0.0 in one hop, which is fully supported — the 3.0.0 Repair step is a no-op for you since your rows are already under `integration_suitecrm`.
