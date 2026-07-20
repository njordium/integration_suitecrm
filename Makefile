# SuiteCRM integration for Nextcloud — dev-stack shortcuts.
#
# Everything in here delegates to `docker compose -f docker/docker-compose.yml`
# with the app's env-file loaded. Feel free to run those commands directly
# if you prefer.
#
# @Code Changes by: Kim Haverblad, 2026

COMPOSE ?= docker compose -f docker/docker-compose.yml --env-file docker/.env

.PHONY: help up down restart logs ps build rebuild reset \
        occ occ-shell shell-nc shell-crm psql-nc psql-crm \
        seed-crm status

help: ## Show this help
	@awk 'BEGIN{FS":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n\nTargets:\n"} \
	    /^[a-zA-Z0-9_-]+:.*##/ {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

up: ## Bring the stack up in the background
	$(COMPOSE) up -d --build

down: ## Stop the stack (data volumes preserved)
	$(COMPOSE) down

restart: down up ## Down then up

logs: ## Tail logs from every container
	$(COMPOSE) logs -f --tail=200

ps: ## List running containers
	$(COMPOSE) ps

build: ## (Re)build local images (SuiteCRM Dockerfile)
	$(COMPOSE) build

rebuild: ## Rebuild with --no-cache
	$(COMPOSE) build --no-cache

reset: ## FULL RESET — down + delete all volumes + up (destroys data)
	$(COMPOSE) down -v
	$(COMPOSE) up -d --build

# ---- Nextcloud helpers ---------------------------------------------------

occ: ## Run `occ CMD="app:list"` inside the Nextcloud container
	$(COMPOSE) exec -u www-data nextcloud php occ $(CMD)

occ-shell: ## Interactive occ shell (occ:completion + occ:list)
	$(COMPOSE) exec -u www-data nextcloud php occ list

shell-nc: ## Shell into the Nextcloud container
	$(COMPOSE) exec nextcloud bash

psql-nc: ## MariaDB client on the Nextcloud database
	$(COMPOSE) exec nc-db mysql -uroot -prootpw nextcloud

# ---- SuiteCRM helpers ----------------------------------------------------

shell-crm: ## Shell into the SuiteCRM container
	$(COMPOSE) exec suitecrm bash

psql-crm: ## MariaDB client on the SuiteCRM database
	$(COMPOSE) exec crm-db mysql -uroot -prootpw suitecrm

seed-crm: ## Re-run the OAuth2 client seed (idempotent)
	$(COMPOSE) exec suitecrm /usr/local/bin/entrypoint.sh /bin/true \
	    || echo "(entrypoint returned non-zero — check whether install marker exists; use 'make reset' for a clean install)"

# ---- Status --------------------------------------------------------------

status: ## Show the URLs to hit
	@echo "Nextcloud : http://localhost:$${NC_PORT:-8080}    (admin/admin)"
	@echo "SuiteCRM  : http://localhost:$${CRM_PORT:-8081}   (admin/admin)"
	@echo ""
	@echo "OAuth client seeded on SuiteCRM:"
	@echo "  client_id     : $${OAUTH_CLIENT_ID:-nc-dev-client}"
	@echo "  redirect_uri  : $${OAUTH_REDIRECT_URI:-http://localhost:8080/apps/integration_suitecrm/oauth-callback}"
