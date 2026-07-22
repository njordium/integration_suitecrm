# Upgrading from 1.9.x to 2.0.0

Version 2.0.0 renames the Nextcloud app id from `integration_suitecrm` to `njordium_suitecrm`. This is the only breaking change in the release. Every setting on your existing 1.9.x install, admin OAuth config, the SuiteCRM instance URL, the client id/secret, the authorize path, every per-user OAuth token, carries across automatically via a Repair step that runs on `occ upgrade`. Users do not need to re-authorise SuiteCRM.

The rename exists because the Nextcloud App Store still lists `integration_suitecrm` as Julien Veyssier's original app (last updated November 2021, version 1.0.3), and NC merges that stale record into what it shows for our fork. Renaming the id cleanly detaches the fork from the App Store cache.

## Prerequisites

- A working 1.9.x install (any 1.9.x patch level is fine, the migration is version-agnostic within the 1.9.x line).
- Shell access to the Nextcloud host and permission to run `occ` as `www-data`.
- The `njordium_suitecrm-v2.0.0.zip` release asset and its `.sha256`.

## Migration steps

Examples are for a docker-compose deployment where the Nextcloud container is `nextcloud-nextcloud-1` and `custom_apps/` is bind-mounted at `/opt/nextcloud/data/nc/custom_apps/`. Adapt paths for LXC, bare metal, or NC AIO.

### 1. Verify the checksum

```bash
cd /tmp
curl -LO https://github.com/njordium/integration_suitecrm/releases/download/v2.0.0/njordium_suitecrm-v2.0.0.zip
curl -LO https://github.com/njordium/integration_suitecrm/releases/download/v2.0.0/njordium_suitecrm-v2.0.0.zip.sha256
sha256sum -c njordium_suitecrm-v2.0.0.zip.sha256
# Expected: njordium_suitecrm-v2.0.0.zip: OK
```

### 2. Disable the old app

```bash
docker exec -u www-data nextcloud-nextcloud-1 php occ app:disable integration_suitecrm
```

The database rows keyed under `appid = 'integration_suitecrm'` remain untouched.

### 3. Extract v2.0.0 into `custom_apps/`

```bash
unzip -q /tmp/njordium_suitecrm-v2.0.0.zip -d /opt/nextcloud/data/nc/custom_apps/
chown -R 33:33 /opt/nextcloud/data/nc/custom_apps/njordium_suitecrm
```

The zip contains a top-level `njordium_suitecrm/` directory that matches the new app id, this is required for NC to load the app.

### 4. Enable the new app

```bash
docker exec -u www-data nextcloud-nextcloud-1 php occ app:enable njordium_suitecrm
```

Enabling the app fires the `CopyLegacyAppConfig` Repair step. It emits a summary line to `occ` output such as:

```
Migrated 4 admin config key(s) and 7 user preference row(s) from "integration_suitecrm" to "njordium_suitecrm".
```

On a fresh install with no legacy rows, the step is silent.

### 5. Update the SuiteCRM OAuth2 Client Redirect URL

**Important**: the Repair step migrates settings *inside Nextcloud* under the new app id, but it cannot reach into your SuiteCRM installation. The OAuth2 Client you created for 1.9.x still points its Redirect URL at `.../apps/integration_suitecrm/oauth-callback`, which no longer resolves after the rename. Existing per-user tokens keep working (they were already issued), but any user who needs to reconnect (or a fresh admin who tries **Connect via SuiteCRM OAuth**) will hit a blank authorize page or a callback 404.

In SuiteCRM, go to **Admin → Users & Authentication → OAuth2 Clients and Tokens**, open the client used for the Nextcloud integration, and change its **Redirect URL** from:

```
https://cloud.example.com/apps/integration_suitecrm/oauth-callback
```

to:

```
https://cloud.example.com/apps/njordium_suitecrm/oauth-callback
```

Save. No restart required on the SuiteCRM side.

### 6. Verify

- `occ app:list | grep -E 'integration_suitecrm|njordium_suitecrm'`, old app should be disabled, new app enabled at 2.0.0.
- **Settings → Administration → Connected accounts → SuiteCRM integration** shows your existing instance URL, application id, and authorize path pre-filled. The client secret shows as "A secret is stored, type to replace".
- **Dashboard** widgets render.
- One user runs their normal SuiteCRM search or opens the calendar widget, no OAuth prompt appears; the copied per-user tokens work.
- One user goes through **Disconnect** then **Connect via SuiteCRM OAuth** to prove the updated Redirect URL round-trips cleanly.

### 7. Optional, clean up the old folder

Once you have verified 2.0.0 is stable in your environment:

```bash
rm -rf /opt/nextcloud/data/nc/custom_apps/integration_suitecrm
```

The database rows under the legacy `appid` stay behind until a follow-up 2.1.0 Repair step removes them; leaving them in place keeps the rollback path below trivial.

## Rollback

If 2.0.0 misbehaves in your environment, roll back in the reverse order:

```bash
docker exec -u www-data nextcloud-nextcloud-1 php occ app:disable njordium_suitecrm
docker exec -u www-data nextcloud-nextcloud-1 php occ app:enable integration_suitecrm
```

The 1.9.x rows were never touched by the Migration step, so re-enabling the old app returns everything to the exact state it was in before the upgrade. If you also removed the old `custom_apps/integration_suitecrm/` folder in step 6, re-extract a 1.9.1 release zip first.

Note that any change made in 2.0.0 (a user reconnecting under the new id, an admin changing the instance URL under the new id) will not automatically flow back to the legacy rows, after a rollback the app runs against the pre-upgrade snapshot.

## Verifying the Repair step directly

The step is registered under `<repair-steps><post-migration>` in `appinfo/info.xml` and runs automatically on every `occ upgrade`, including the implicit one that fires on `app:enable`. You can re-run it manually at any time:

```bash
docker exec -u www-data nextcloud-nextcloud-1 php occ maintenance:repair
```

It is idempotent: running it twice does not duplicate rows because it checks for the existence of each target key before inserting.

## Reporting problems

Please file issues at [github.com/njordium/integration_suitecrm/issues](https://github.com/njordium/integration_suitecrm/issues). For migration-specific problems, include the full `occ app:enable njordium_suitecrm` output, and if possible the row counts from before and after:

```bash
# Legacy row counts
docker exec nextcloud-nc-db-1 mariadb -u root -p<password> nextcloud \
  -e "SELECT COUNT(*) FROM oc_appconfig WHERE appid IN ('integration_suitecrm','njordium_suitecrm')
        UNION ALL
      SELECT COUNT(*) FROM oc_preferences WHERE appid IN ('integration_suitecrm','njordium_suitecrm');"
```

Substitute the correct DB container name, root password, and NC database name for your environment.
