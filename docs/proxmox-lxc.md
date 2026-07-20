# Production reference: Proxmox LXC (Debian)

Working reference for running Nextcloud + SuiteCRM 8.x on a single Proxmox host using two Debian 12 LXCs and nginx on the PVE host as the public entry point. This is the deployment pattern the fork is being maintained against in production.

Nothing here is Nextcloud-specific — an identical layout on bare metal Debian or in a VM (single host or split) works the same, minus the `pct` commands.

## Layout

```
                     ┌────────────────────────┐
   Internet ─443─▶   │  nginx (PVE host)      │
                     │  · TLS termination      │
                     │  · HSTS                 │
                     │  · X-Forwarded-*        │
                     └─┬────────────────────┬──┘
                       │                    │
              cloud.example.com    crm.example.com
                       │                    │
              ┌────────▼────────┐  ┌────────▼────────┐
              │  LXC 101         │  │  LXC 102         │
              │  Nextcloud       │  │  SuiteCRM 8      │
              │  · Apache        │  │  · Apache        │
              │  · PHP 8.2-fpm   │  │  · PHP 8.2-fpm   │
              │  · MariaDB       │  │  · MariaDB       │
              │  10.10.10.101    │  │  10.10.10.102    │
              └─────────┬────────┘  └────────┬─────────┘
                        │                     │
                        └────── vmbr0 ────────┘
                              (PVE bridge)
```

Two LXCs share the PVE bridge so they can talk to each other on RFC-1918 addresses. The Nextcloud LXC needs `allow_local_remote_servers=true` to reach SuiteCRM's internal IP for the OAuth flow. The public reverse proxy is on the PVE host itself, terminating TLS and forwarding to whichever LXC based on Host header.

## PVE host: create the LXCs

```bash
# On the Proxmox host, download the Debian 12 template if you don't already have it
pveam update
pveam download local debian-12-standard_12.7-1_amd64.tar.zst

# Create the Nextcloud LXC
pct create 101 local:vztmpl/debian-12-standard_12.7-1_amd64.tar.zst \
    --hostname nextcloud \
    --cores 4 --memory 4096 --swap 2048 \
    --rootfs local-lvm:32 \
    --net0 name=eth0,bridge=vmbr0,ip=10.10.10.101/24,gw=10.10.10.1 \
    --nameserver 1.1.1.1 \
    --unprivileged 1 \
    --features nesting=1 \
    --onboot 1 \
    --ostype debian

# Create the SuiteCRM LXC (bigger RAM, bigger disk)
pct create 102 local:vztmpl/debian-12-standard_12.7-1_amd64.tar.zst \
    --hostname suitecrm \
    --cores 4 --memory 6144 --swap 2048 \
    --rootfs local-lvm:64 \
    --net0 name=eth0,bridge=vmbr0,ip=10.10.10.102/24,gw=10.10.10.1 \
    --nameserver 1.1.1.1 \
    --unprivileged 1 \
    --features nesting=1 \
    --onboot 1 \
    --ostype debian

pct start 101
pct start 102
```

The PHP 8.2 ondrej repository doesn't ship in Debian 12's default sources, so we install PHP 8.2 explicitly on both LXCs (Debian 12 defaults to PHP 8.2 as of `bookworm-backports`; adjust if you're on a different Debian release).

## Nextcloud LXC (101)

```bash
pct enter 101

apt update && apt upgrade -y
apt install -y \
    apache2 \
    mariadb-server \
    php php-fpm php-mysql php-gd php-curl php-xml php-mbstring \
    php-intl php-zip php-bz2 php-imagick php-bcmath php-gmp \
    libapache2-mod-php \
    redis php-redis \
    unzip wget cron

# --- MariaDB ---
mysql -e "CREATE DATABASE nextcloud CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
mysql -e "CREATE USER 'nextcloud'@'localhost' IDENTIFIED BY 'CHANGEME_LONG_RANDOM_PW';"
mysql -e "GRANT ALL ON nextcloud.* TO 'nextcloud'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

# --- Nextcloud download ---
cd /tmp
wget https://download.nextcloud.com/server/releases/latest-30.tar.bz2
tar -xjf latest-30.tar.bz2 -C /var/www/
chown -R www-data:www-data /var/www/nextcloud

# --- Apache vhost ---
cat > /etc/apache2/sites-available/nextcloud.conf <<'APACHECONF'
<VirtualHost *:80>
    DocumentRoot /var/www/nextcloud/
    ServerName cloud.example.com

    <Directory /var/www/nextcloud/>
        Require all granted
        AllowOverride All
        Options FollowSymLinks MultiViews
        <IfModule mod_dav.c>
            Dav off
        </IfModule>
    </Directory>

    # Trust the PVE-host proxy for X-Forwarded-* headers
    RequestHeader set X-Forwarded-Proto "https"
</VirtualHost>
APACHECONF

a2enmod rewrite headers env dir mime ssl
a2dissite 000-default.conf
a2ensite nextcloud.conf
systemctl restart apache2

# --- Run the NC installer ---
sudo -u www-data php /var/www/nextcloud/occ maintenance:install \
    --database "mysql" --database-name "nextcloud" \
    --database-user "nextcloud" --database-pass "CHANGEME_LONG_RANDOM_PW" \
    --admin-user "admin" --admin-pass "CHANGEME_ADMIN_PW"

# --- Post-install config for reverse-proxy awareness ---
sudo -u www-data php /var/www/nextcloud/occ config:system:set overwriteprotocol --value=https
sudo -u www-data php /var/www/nextcloud/occ config:system:set overwritehost --value=cloud.example.com
sudo -u www-data php /var/www/nextcloud/occ config:system:set overwritecondaddr --value='^10\.10\.10\.1$'
sudo -u www-data php /var/www/nextcloud/occ config:system:set trusted_proxies 0 --value=10.10.10.1
sudo -u www-data php /var/www/nextcloud/occ config:system:set trusted_domains 1 --value=cloud.example.com
sudo -u www-data php /var/www/nextcloud/occ config:system:set overwrite.cli.url --value=https://cloud.example.com

# --- Allow reaching the SuiteCRM LXC on 10.10.10.102 ---
sudo -u www-data php /var/www/nextcloud/occ config:system:set allow_local_remote_servers --value=true --type=boolean

# --- Systemd timer for NC cron (replaces webcron / crontab) ---
cat > /etc/systemd/system/nextcloud-cron.service <<'SVC'
[Unit]
Description=Nextcloud cron.php job

[Service]
User=www-data
ExecCondition=php -f /var/www/nextcloud/occ status -e
ExecStart=/usr/bin/php -f /var/www/nextcloud/cron.php
KillMode=process
SVC

cat > /etc/systemd/system/nextcloud-cron.timer <<'TMR'
[Unit]
Description=Run Nextcloud cron.php every 5 minutes

[Timer]
OnBootSec=5min
OnUnitActiveSec=5min
Unit=nextcloud-cron.service

[Install]
WantedBy=timers.target
TMR

systemctl daemon-reload
systemctl enable --now nextcloud-cron.timer
```

## SuiteCRM LXC (102)

```bash
pct enter 102

apt update && apt upgrade -y
apt install -y \
    apache2 \
    mariadb-server \
    php php-fpm php-mysql php-gd php-curl php-xml php-mbstring \
    php-intl php-zip php-imap php-soap php-ldap php-bcmath \
    libapache2-mod-php \
    unzip wget curl openssl cron

# --- MariaDB ---
mysql -e "CREATE DATABASE suitecrm CHARACTER SET utf8mb4;"
mysql -e "CREATE USER 'suitecrm'@'localhost' IDENTIFIED BY 'CHANGEME_LONG_RANDOM_PW_2';"
mysql -e "GRANT ALL ON suitecrm.* TO 'suitecrm'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

# --- SuiteCRM 8 download (get the URL from the current GitHub release) ---
cd /tmp
curl -fL -o suitecrm.zip \
    https://github.com/salesagility/SuiteCRM-Core/releases/download/v8.10.1/SuiteCRM-8.10.1.zip
mkdir -p /var/www/suitecrm
unzip -q suitecrm.zip -d /var/www/suitecrm
chown -R www-data:www-data /var/www/suitecrm

# --- Symfony .env ---
cat > /var/www/suitecrm/.env.local <<ENVEOF
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=$(openssl rand -hex 32)
DATABASE_URL="mysql://suitecrm:CHANGEME_LONG_RANDOM_PW_2@127.0.0.1:3306/suitecrm?serverVersion=10.11-MariaDB&charset=utf8mb4"
ENVEOF
chown www-data:www-data /var/www/suitecrm/.env.local
chmod 640 /var/www/suitecrm/.env.local

# --- Apache vhost ---
cat > /etc/apache2/sites-available/suitecrm.conf <<'APACHECONF'
<VirtualHost *:80>
    DocumentRoot /var/www/suitecrm/public
    ServerName crm.example.com

    RewriteEngine on
    RewriteRule ^/$ /public/ [R=301,L]

    <Directory /var/www/suitecrm/public>
        Require all granted
        AllowOverride All
        Options FollowSymLinks
    </Directory>

    RequestHeader set X-Forwarded-Proto "https"
</VirtualHost>
APACHECONF

a2enmod rewrite headers env dir mime
a2dissite 000-default.conf
a2ensite suitecrm.conf
systemctl restart apache2

# --- Complete SuiteCRM install via the web UI at http://10.10.10.102/install.php
#     (or, if you can reach it via nginx, https://crm.example.com/install.php).
#     Use the DB creds above. Set the site URL to https://crm.example.com. ---

# --- After install: generate OAuth2 keys ---
sudo -u www-data mkdir -p /var/www/suitecrm/public/legacy/Api/V8/OAuth2
cd /var/www/suitecrm/public/legacy/Api/V8/OAuth2
sudo -u www-data openssl genrsa -out private.key 2048
sudo -u www-data openssl rsa -in private.key -pubout -out public.key
chmod 600 private.key && chmod 644 public.key

# --- SuiteCRM scheduler (its own cron equivalent) ---
crontab -u www-data -l 2>/dev/null | \
    { cat; echo '*    *    *    *    * cd /var/www/suitecrm/public/legacy; php -f cron.php > /dev/null 2>&1'; } | \
    crontab -u www-data -
```

Then in SuiteCRM's admin UI, create the OAuth2 Client (Admin → OAuth2 Clients and Tokens → New), set redirect URL to `https://cloud.example.com/apps/integration_suitecrm/oauth-callback`, note the client ID + secret, and configure them in Nextcloud's admin panel with instance URL `https://crm.example.com`.

## PVE host: reverse proxy

Install nginx on the PVE host itself, then follow [reverse-proxy.md](reverse-proxy.md). Two `server` blocks — one for `cloud.example.com` proxying to `http://10.10.10.101:80`, one for `crm.example.com` proxying to `http://10.10.10.102:80`. Both with LetsEncrypt certs via certbot.

## Verification

From the NC LXC:

```bash
# The app's built-in diagnostic — hits every layer that has failed for anyone
sudo -u www-data php /var/www/nextcloud/occ integration_suitecrm:test-connection
```

Expected output (with all checks passing):

```
SuiteCRM integration — connection diagnostic

  ✓ Admin config: oauth_instance_url = https://crm.example.com
  ✓ Admin config: client_id = <your-id>
  ✓ Admin config: client_secret is set (hidden)
  ✓ Admin config: oauth_authorize_path = /Api/authorize
  ✓ SSRF guard: host "crm.example.com" is public — no whitelist needed
  ✓ HTTP reachability: https://crm.example.com → HTTP 200
  ✓ Authorize endpoint (/Api/authorize): HTTP 302 (OK)
  ✓ Token endpoint (/Api/access_token): HTTP 400 with error="unsupported_grant_type" (OK)

All checks passed. Users should be able to complete the OAuth flow.
```

## Backup

Two things matter for restore-in-place to work:

**1. Nextcloud's config.php `secret`.** OAuth tokens stored by this app are encrypted with NC's `ICrypto` service, which uses the `secret` from `config.php`. Restore NC to a different host with a different `secret` and every user has to reconnect.

```bash
# Include in your backup
/var/www/nextcloud/config/config.php
/var/www/nextcloud/data/         # user files + NC data
```

**2. SuiteCRM's OAuth2 OpenSSL keys.** Restoring SuiteCRM to a host with different `Api/V8/OAuth2/{private,public}.key` files invalidates every issued JWT immediately (they were signed with the old private key).

```bash
# Include in your backup
/var/www/suitecrm/                      # everything, but especially:
/var/www/suitecrm/public/legacy/Api/V8/OAuth2/private.key
/var/www/suitecrm/public/legacy/Api/V8/OAuth2/public.key
/var/www/suitecrm/.env.local
```

Plus of course both MariaDB dumps.

Simple daily backup script that goes on the PVE host and dumps both LXCs:

```bash
#!/bin/bash
# /root/backup-integration.sh
set -euo pipefail
BACKUP_DIR=/opt/backup/$(date +%Y%m%d)
mkdir -p "$BACKUP_DIR"

# Nextcloud DB
pct exec 101 -- bash -c 'mysqldump --single-transaction nextcloud | gzip' \
    > "$BACKUP_DIR/nextcloud.sql.gz"

# SuiteCRM DB
pct exec 102 -- bash -c 'mysqldump --single-transaction suitecrm | gzip' \
    > "$BACKUP_DIR/suitecrm.sql.gz"

# Nextcloud config + data
pct exec 101 -- tar -czf - /var/www/nextcloud/config /var/www/nextcloud/data \
    > "$BACKUP_DIR/nextcloud-files.tar.gz"

# SuiteCRM install (includes keys, .env.local, uploaded files)
pct exec 102 -- tar -czf - /var/www/suitecrm \
    > "$BACKUP_DIR/suitecrm-files.tar.gz"

# Keep 30 days
find /opt/backup -maxdepth 1 -type d -name '20*' -mtime +30 -exec rm -rf {} +
```

Schedule via a systemd timer on the PVE host:

```bash
cat > /etc/systemd/system/integration-backup.service <<'SVC'
[Unit]
Description=Nightly integration backup

[Service]
Type=oneshot
ExecStart=/root/backup-integration.sh
SVC

cat > /etc/systemd/system/integration-backup.timer <<'TMR'
[Unit]
Description=Nightly integration backup at 03:00

[Timer]
OnCalendar=*-*-* 03:00:00
Persistent=true
Unit=integration-backup.service

[Install]
WantedBy=timers.target
TMR

systemctl enable --now integration-backup.timer
```

## Upgrade path

**Nextcloud minor version (30.x.y → 30.x.z):** built-in updater is safe. Run from the LXC:
```bash
sudo -u www-data php /var/www/nextcloud/occ upgrade
```

**Nextcloud major version (30 → 31):** check the integration_suitecrm `info.xml` `<nextcloud max-version="X">` first. The fork is currently pinned to `max-version="34"`. If NC ships version > 34, wait for a fork release that bumps this.

**SuiteCRM minor version:** SuiteCRM 8's [update process](https://docs.suitecrm.com/admin/administration-panel/upgrade-wizard/) via the Admin → Upgrade Wizard. Don't touch the `Api/V8/OAuth2/*.key` files during upgrade — they must survive.

**integration_suitecrm fork:** replace the `/var/www/nextcloud/apps/integration_suitecrm` directory with a fresh extract of the latest release zip from GitHub, then:
```bash
sudo -u www-data php /var/www/nextcloud/occ upgrade
sudo -u www-data php /var/www/nextcloud/occ maintenance:repair --include-expensive
```
Existing users' OAuth tokens survive because they're re-encrypted with the same NC `secret`.

## Firewall

If you're using Proxmox's built-in firewall or ufw on the PVE host:

```bash
# On PVE host — allow only HTTPS from internet, SSH from admin IPs
ufw default deny incoming
ufw allow from <your-admin-cidr> to any port 22 proto tcp
ufw allow 80/tcp   # http → https redirect only
ufw allow 443/tcp
ufw enable
```

The LXCs themselves don't need incoming rules from the internet — they only talk to the PVE-host nginx and to each other over the bridge.

## Monitoring

Two systemd units to watch:

```bash
# On the NC LXC
systemctl status nextcloud-cron.timer         # ensures cron runs
journalctl -u nextcloud-cron.service --since '1 day ago'

# On both LXCs
systemctl status apache2
journalctl -u apache2 --since '1 hour ago' | grep -i error
```

And Nextcloud's own log:

```bash
sudo -u www-data php /var/www/nextcloud/occ log:tail -f
# or
tail -f /var/www/nextcloud/data/nextcloud.log
```

Filter by app for anything the integration logs:

```bash
tail -f /var/www/nextcloud/data/nextcloud.log | grep integration_suitecrm
```
