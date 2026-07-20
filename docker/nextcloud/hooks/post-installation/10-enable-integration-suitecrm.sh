#!/usr/bin/env bash
# Nextcloud post-installation hook.
#
# Runs after the standard nextcloud:*-apache image finishes its own install
# step (create db schema, create admin user). We use it to enable the
# integration_suitecrm app and pre-fill the admin OAuth config so the stack
# is usable straight after `docker compose up`.
#
# All values come from env vars set in docker-compose.yml.
#
# @Code Changes by: Kim Haverblad, 2026
set -euo pipefail

log() { echo "[nc-post-install] $*" >&2; }

log "enabling integration_suitecrm..."
php occ app:enable integration_suitecrm

log "seeding admin OAuth config..."
php occ config:app:set integration_suitecrm oauth_instance_url \
    --value="${SUITECRM_URL_INTERNAL:-http://suitecrm}"

php occ config:app:set integration_suitecrm client_id \
    --value="${SUITECRM_CLIENT_ID:-nc-dev-client}"

php occ config:app:set integration_suitecrm client_secret \
    --value="${SUITECRM_CLIENT_SECRET:-nc-dev-secret}"

# Same default the ConfigController + Admin.php ship with — set it
# explicitly so a `config:app:get` shows the current value in the UI.
php occ config:app:set integration_suitecrm oauth_authorize_path \
    --value="/Api/authorize"

log "post-install hook complete"
