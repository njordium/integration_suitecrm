# Local dev stack

Four containers: Nextcloud + its MariaDB, SuiteCRM 8 + its MariaDB. Brought up with a single `make up`. The app source is bind-mounted into Nextcloud so PHP edits go live on the next request.

## First-time setup

```bash
cp docker/.env.example docker/.env      # tweak ports/versions if needed
make up                                 # ~5 min on first run (SuiteCRM image build + install)
make logs                               # watch the install finish
make status                             # prints the URLs and OAuth client id
```

When the logs settle you'll have:

- **Nextcloud** — <http://localhost:8080>  ·  login `admin` / `admin`
- **SuiteCRM** — <http://localhost:8081>  ·  login `admin` / `admin`

The integration app is already enabled and the admin OAuth config is already filled in — check under **Settings → Administration → Connected accounts → SuiteCRM integration** and you should see `http://suitecrm` as the instance URL and `nc-dev-client` as the application ID. Per-user connection is then a matter of visiting **Settings → Personal → Connected accounts → SuiteCRM integration** and clicking **Connect via SuiteCRM OAuth**.

## Everyday commands

```bash
make up            # start the stack
make down          # stop it (data volumes preserved)
make restart       # down + up
make logs          # tail every container
make ps            # what's running
make status        # print the URLs + seeded OAuth client id

make occ CMD="app:list"                 # run occ inside the NC container
make occ CMD="config:app:get integration_suitecrm oauth_instance_url"
make shell-nc                            # shell into the NC container
make shell-crm                           # shell into the SuiteCRM container
make psql-nc                             # MariaDB client on the NC DB
make psql-crm                            # MariaDB client on the SuiteCRM DB

make reset                               # wipe volumes and start fresh
```

## What the containers do

| Service | Image | Purpose |
|---|---|---|
| `nc-db` | `mariadb:11.4` | Nextcloud's database |
| `nextcloud` | `nextcloud:${NC_VERSION}-apache` (default 30) | Nextcloud, with the app bind-mounted read-only under `custom_apps/integration_suitecrm` |
| `crm-db` | `mariadb:11.4` | SuiteCRM's database |
| `suitecrm` | Built from `docker/suitecrm/Dockerfile` | SuiteCRM 8.10.x on `php:8.2-apache`. First boot runs `bin/console suitecrm:app:install`, generates the OAuth2 OpenSSL keypair, and seeds a known-constant OAuth2 client |

Nextcloud reaches SuiteCRM on the internal docker network as `http://suitecrm`. The `oauth_instance_url` app config is set to exactly that string by the NC post-install hook — no host-visible port needed for the machine-to-machine calls. The host-visible port (`8081`) is only there so you can open the SuiteCRM UI in your own browser.

## Editing the app

The repo is bind-mounted into `/var/www/html/custom_apps/integration_suitecrm` **read-only**. Edits to `lib/*.php`, `templates/*.php`, and `appinfo/routes.php` are picked up on the next request.

Changes under `src/` (Vue 3) need `npm run build` on the host — the compiled bundles land in `js/` which is inside the same bind mount, so once built they're immediately live in Nextcloud too.

If you want write-through so `occ app:enable` and the like can touch the app dir, drop the `:ro` suffix from the volume mount in `docker-compose.yml`.

## OAuth flow — trying it end-to-end

1. Log in to Nextcloud as `admin`.
2. Go to **Settings → Personal → Connected accounts → SuiteCRM integration**.
3. Click **Connect via SuiteCRM OAuth (recommended)**.
4. You'll be redirected to `http://localhost:8081/Api/authorize` on SuiteCRM.
5. Log in as `admin` / `admin` on SuiteCRM if not already.
6. Approve the consent screen.
7. SuiteCRM redirects back to `http://localhost:8080/apps/integration_suitecrm/oauth-callback?code=…&state=…`, the app exchanges the code for an access token, and lands you back in Personal Settings marked connected.

If step 4 lands on a 404, the seeded authorize path may not match your SuiteCRM build — flip it via **Settings → Administration → Connected accounts → SuiteCRM integration → OAuth authorize endpoint path** (as of v1.9.1 it's editable in the UI). `/legacy/oauth2/authorize` is the fallback for older 8.x builds.

## Running the PHPUnit suite against a real database

```bash
make shell-nc
cd /var/www/html/custom_apps/integration_suitecrm
composer install
vendor/bin/phpunit --testdox
```

## Troubleshooting

**"database ${DB_HOST} did not come up in time"** — MariaDB init on the first boot can take 30-60s on slow disks. Bump `DB_READY_TIMEOUT` in the SuiteCRM service env if you regularly see this.

**SuiteCRM installer fails** — the container writes the installer's stdout+stderr to the docker log stream (`make logs`). Common causes: wrong `SUITECRM_SITE_URL` (must be exactly `http://suitecrm` for internal NC calls to work), incompatible PHP extension version, corrupt download. `make reset` clears state so you can retry cleanly.

**OAuth callback lands on `error: state_mismatch`** — the browser has a stale `oauth_state` cookie from a previous connect attempt. Sign out of Nextcloud, clear cookies for `localhost:8080`, sign back in, retry.

**Port 8080 or 8081 already in use** — set `NC_PORT` or `CRM_PORT` in `docker/.env` to a free port and `make restart`.
