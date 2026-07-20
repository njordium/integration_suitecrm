#!/usr/bin/env bash
# SuiteCRM container entrypoint.
#
# Idempotent — on first boot it runs the SuiteCRM installer, generates OAuth2
# OpenSSL keys, and seeds the OAuth2 client row Nextcloud will authenticate
# against. On every subsequent boot it just exec's apache.
#
# All required inputs come from environment variables (see docker-compose.yml).
#
# @Code Changes by: Kim Haverblad, 2026
set -euo pipefail

INSTALL_MARKER="/var/www/html/.suitecrm-installed"
APP_ROOT="/var/www/html"
DB_READY_TIMEOUT="${DB_READY_TIMEOUT:-60}"

log() { echo "[suitecrm-init] $*" >&2; }

wait_for_db() {
  log "Waiting up to ${DB_READY_TIMEOUT}s for ${DB_HOST}..."
  local i=0
  until mysqladmin ping -h"${DB_HOST}" -u"${DB_USER}" -p"${DB_PASSWORD}" --silent 2>/dev/null; do
    i=$((i+1))
    if [ "$i" -ge "${DB_READY_TIMEOUT}" ]; then
      log "database ${DB_HOST} did not come up in time"; exit 1
    fi
    sleep 1
  done
  log "database ready"
}

install_suitecrm() {
  log "running SuiteCRM 8 installer..."
  cd "${APP_ROOT}"

  # SuiteCRM 8 ships a CLI installer under bin/console. It takes a JSON blob
  # via env variable (SCRM_INSTALL_INPUTS_FILE) or command-line flags.
  # bin/console suitecrm:app:install is the modern entry point.
  php bin/console suitecrm:app:install \
    -u "${SUITECRM_ADMIN_USER}" \
    -p "${SUITECRM_ADMIN_PASSWORD}" \
    -U "${DB_USER}" \
    -P "${DB_PASSWORD}" \
    -H "${DB_HOST}" \
    -N "${DB_NAME}" \
    -S "${SUITECRM_SITE_URL}" \
    -d "no"

  chown -R www-data:www-data "${APP_ROOT}"
  log "SuiteCRM installed"
}

generate_oauth_keys() {
  local keydir="${APP_ROOT}/Api/V8/OAuth2"
  mkdir -p "${keydir}"
  if [ ! -f "${keydir}/private.key" ]; then
    log "generating OAuth2 OpenSSL keypair..."
    openssl genrsa -out "${keydir}/private.key" 2048
    openssl rsa -in "${keydir}/private.key" -pubout -out "${keydir}/public.key"
    chmod 600 "${keydir}/private.key"
    chmod 644 "${keydir}/public.key"
    chown -R www-data:www-data "${keydir}"
  else
    log "OAuth2 keys already present, leaving alone"
  fi
}

seed_oauth_client() {
  log "seeding OAuth2 client ${OAUTH_CLIENT_ID}..."
  # SuiteCRM 8 stores password-secured secrets bcrypt-hashed. We produce the
  # hash via PHP so this stays portable across mariadb versions.
  local hashed
  hashed="$(php -r 'echo password_hash(getenv("OAUTH_CLIENT_SECRET"), PASSWORD_BCRYPT);')"

  export SEED_HASHED_SECRET="${hashed}"
  envsubst < /opt/seed/seed-oauth-client.sql \
    | mysql -h"${DB_HOST}" -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}"
  log "OAuth2 client seeded"
}

main() {
  wait_for_db

  if [ ! -f "${INSTALL_MARKER}" ]; then
    install_suitecrm
    generate_oauth_keys
    seed_oauth_client
    date > "${INSTALL_MARKER}"
    log "first-boot init complete"
  else
    log "already installed, skipping first-boot init"
  fi

  log "starting apache..."
  exec "$@"
}

main "$@"
