# Running the integration behind a reverse proxy

Public-facing Nextcloud deployments almost always live behind nginx, Apache, or a Cloudflare Tunnel. The OAuth flow this app implements only works if Nextcloud generates URLs that match what the browser is really hitting — one wrong header and SuiteCRM rejects the callback with `invalid_client`.

This document captures the tested config for those three setups. HAProxy and Traefik front-ends work fine in principle (the underlying constraint is the same overwrite trio below), but we don't ship tested samples for them yet — if you run one of those and want to contribute a working config, PRs welcome.

## The failure mode you're trying to avoid

The OAuth authorization-code flow encodes a `redirect_uri` when it sends the user to SuiteCRM's consent screen. Nextcloud generates that URI from its own base URL. If the base URL is wrong — say Nextcloud thinks it's `http://internal-vm:8080` but the browser is on `https://cloud.example.com` — then:

1. The `redirect_uri` sent to SuiteCRM says `http://internal-vm:8080/apps/integration_suitecrm/oauth-callback`
2. SuiteCRM tries to match it against the whitelisted redirect URI on the OAuth2 client
3. The whitelisted one is `https://cloud.example.com/apps/integration_suitecrm/oauth-callback` (as it must be for the browser to reach it)
4. Byte-for-byte mismatch → SuiteCRM returns 401 `invalid_client`

The fix is to tell Nextcloud its public URL via the "overwrite" config trio.

## Nextcloud config that always applies

In `/var/www/nextcloud/config/config.php`:

```php
<?php
$CONFIG = [
    // ... your existing entries ...

    // Tell NC what its public URL actually is
    'overwriteprotocol' => 'https',
    'overwritehost' => 'cloud.example.com',
    'overwrite.cli.url' => 'https://cloud.example.com',

    // Only apply the overwrites when the request comes from the proxy —
    // this preserves direct-access URLs (e.g. from the container internal
    // network for occ). Set to your proxy's source IP or CIDR as a PHP
    // regex; the `^...$` and escaped dots below are literal.
    'overwritecondaddr' => '^10\.0\.0\.5$',

    // Trust the proxy's X-Forwarded-* headers
    'trusted_proxies' => ['10.0.0.5'],

    // Add the public hostname to trusted_domains
    'trusted_domains' => [
        0 => 'localhost',
        1 => 'cloud.example.com',
    ],
];
```

Verify with:
```bash
sudo -u www-data php occ config:system:get overwriteprotocol
sudo -u www-data php occ config:system:get overwritehost
sudo -u www-data php occ config:system:get overwritecondaddr
sudo -u www-data php occ config:system:get trusted_proxies
```

And then the SuiteCRM OAuth2 Client's Redirect URL must be exactly:
```
https://cloud.example.com/apps/integration_suitecrm/oauth-callback
```

### About `allow_local_remote_servers`

A common assumption is that fronting SuiteCRM with a public reverse proxy makes `allow_local_remote_servers=true` unnecessary. That's not quite right. Nextcloud's SSRF guard checks the **resolved** IP address of the SuiteCRM URL you set in the app config, not the path the browser takes. If `crm.example.com` resolves to a private-range address (RFC-1918 / loopback), the outbound token exchange from the Nextcloud PHP process still gets blocked before it even reaches your reverse proxy.

Two ways to keep the SSRF guard on:

1. Configure the SuiteCRM app URL as the **public** hostname (`https://crm.example.com`) and make sure that hostname resolves to a **public** IP from the Nextcloud host — usually via public DNS pointing to the reverse proxy's public IP.
2. If NC and the reverse proxy sit on the same private LAN and DNS still resolves the public name to a private IP (split-horizon DNS), you're back to needing `allow_local_remote_servers=true` even though the browser side is fully public.

To check what NC actually sees, run this from inside the NC container/host:
```bash
sudo -u www-data php -r 'var_dump(gethostbyname("crm.example.com"));'
```

If that prints a private-range address you must either fix DNS or set the system value.

## nginx sample (Nextcloud only)

```nginx
# /etc/nginx/sites-available/nextcloud.conf
upstream nextcloud_php_handler {
    server unix:/run/php/php8.2-fpm-nextcloud.sock;
}

server {
    listen 80;
    listen [::]:80;
    server_name cloud.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name cloud.example.com;

    ssl_certificate /etc/letsencrypt/live/cloud.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/cloud.example.com/privkey.pem;

    # HSTS — safe to enable AFTER you've confirmed HTTPS works end-to-end.
    # Once set, browsers refuse plain HTTP to this hostname; hard to undo.
    add_header Strict-Transport-Security "max-age=15768000; includeSubDomains" always;

    add_header X-Content-Type-Options       "nosniff" always;
    add_header X-Frame-Options              "SAMEORIGIN" always;
    add_header Referrer-Policy              "no-referrer" always;
    add_header X-Robots-Tag                 "noindex, nofollow" always;

    # Nextcloud recommends 512M+ for large file uploads
    client_max_body_size 4G;
    fastcgi_buffers 64 4K;

    # Path to the Nextcloud install
    root /var/www/nextcloud;

    location = /.well-known/carddav { return 301 /remote.php/dav/; }
    location = /.well-known/caldav  { return 301 /remote.php/dav/; }

    index index.php index.html /index.php$request_uri;

    location / {
        rewrite ^ /index.php;
    }

    location ~ ^\/(?:build|tests|config|lib|3rdparty|templates|data)\/ {
        deny all;
    }

    location ~ \.php(?:$|/) {
        rewrite ^/(?!index|remote|public|cron|core\/ajax\/update|status|ocs\/v[12]|updater\/.+|oc[ms]-provider\/.+|.+\/richdocumentscode(_arm64)?\/proxy) /index.php$request_uri;

        fastcgi_split_path_info ^(.+?\.php)(/.*)$;
        set $path_info $fastcgi_path_info;

        try_files $fastcgi_script_name =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME       $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO             $path_info;
        fastcgi_param HTTPS                 on;
        fastcgi_param modHeadersAvailable   true;
        fastcgi_param front_controller_active true;

        fastcgi_pass nextcloud_php_handler;
        fastcgi_intercept_errors on;
        fastcgi_request_buffering off;

        fastcgi_read_timeout 600;
    }

    location ~ \.(?:css|js|svg|gif)$ {
        try_files $uri /index.php$request_uri;
        expires 6M;
        access_log off;
    }

    location ~ \.woff2?$ {
        try_files $uri /index.php$request_uri;
        expires 7d;
        access_log off;
    }
}
```

Reload with `nginx -t && systemctl reload nginx`. Confirm from the browser first that `https://cloud.example.com` shows the NC login. Then run the diagnostic:

```bash
sudo -u www-data php occ integration_suitecrm:test-connection
```

## nginx sample (Nextcloud + SuiteCRM behind the same proxy)

If your SuiteCRM lives on `crm.example.com` on the same LAN and only reachable through this reverse proxy, add a second `server` block:

```nginx
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name crm.example.com;

    ssl_certificate /etc/letsencrypt/live/crm.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/crm.example.com/privkey.pem;

    add_header Strict-Transport-Security "max-age=15768000; includeSubDomains" always;

    client_max_body_size 100M;

    location / {
        proxy_pass http://10.0.0.20:80; # your SuiteCRM host+port
        proxy_http_version 1.1;

        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host  $host;

        proxy_read_timeout 180;
        proxy_send_timeout 180;
    }
}
```

Then in Nextcloud's admin config, set `oauth_instance_url = https://crm.example.com` (the public URL, not the private one). Whether you need `allow_local_remote_servers=true` depends on whether `crm.example.com` resolves to a public or private IP from the NC host — see the "About allow_local_remote_servers" section above.

## Apache sample

If you're already using Apache as your web server for Nextcloud, this configuration assumes PHP-FPM (recommended for NC 25+) rather than mod_php:

```apache
# /etc/apache2/sites-available/nextcloud.conf
<VirtualHost *:80>
    ServerName cloud.example.com
    Redirect permanent / https://cloud.example.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName cloud.example.com

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/cloud.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/cloud.example.com/privkey.pem

    # HSTS — safe to enable AFTER you've confirmed HTTPS works end-to-end.
    Header always set Strict-Transport-Security "max-age=15768000; includeSubDomains"

    DocumentRoot /var/www/nextcloud

    <Directory /var/www/nextcloud>
        Require all granted
        AllowOverride All
        Options FollowSymLinks MultiViews

        <IfModule mod_dav.c>
            Dav off
        </IfModule>
    </Directory>

    # PHP-FPM handler — hands .php requests off to the PHP-FPM pool over its
    # Unix socket. Without this block, Apache serves .php files as text
    # (very sharp footgun). Path here matches Debian/Ubuntu default; adjust
    # for your distribution's socket location (RHEL/Fedora usually
    # /run/php-fpm/www.sock).
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.2-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # Tell PHP the browser hit https, even though Apache→PHP-FPM is local.
    # Combined with overwriteprotocol=https in config.php, NC's URL generator
    # will emit https URLs for the OAuth redirect_uri.
    <IfModule mod_setenvif.c>
        SetEnvIf X-Forwarded-Proto "https" HTTPS=on
    </IfModule>
</VirtualHost>
```

Enable the modules Apache needs for this stack:
```bash
a2enmod ssl headers rewrite env dir mime proxy_fcgi setenvif
a2enconf php8.2-fpm  # or the equivalent for your PHP version
systemctl reload apache2
```

If you're running mod_php instead of PHP-FPM (older distros), replace the `<FilesMatch \.php$>` block with `SetHandler application/x-httpd-php` and skip `a2enmod proxy_fcgi`. mod_php is not recommended by upstream Nextcloud on 30+ because it forces `mpm_prefork` and hurts throughput.

The overwrite config in `config.php` still applies — Apache alone doesn't tell PHP the correct scheme unless you set `overwriteprotocol=https`.

## Cloudflare Tunnel

If you're fronting NC via a Cloudflare Tunnel:

```yaml
# ~/.cloudflared/config.yml
ingress:
  - hostname: cloud.example.com
    service: http://localhost:80
    originRequest:
      httpHostHeader: cloud.example.com
      noTLSVerify: false
  - service: http_status:404
```

`noTLSVerify: false` is the safe default (do verify TLS on the backend). Only set to `true` if your backend uses a self-signed cert you can't replace — which is unusual because the tunnel-to-backend leg is typically plain HTTP inside the same host anyway.

`config.php` needs:
```php
'overwriteprotocol' => 'https',
'overwritehost' => 'cloud.example.com',
'trusted_proxies' => ['127.0.0.1'],
'overwritecondaddr' => '^127\.0\.0\.1$',
```

The tunnel presents from localhost (127.0.0.1) as far as NC is concerned.

## Testing your reverse-proxy config

```bash
# 1. Confirm NC reports the public URL
sudo -u www-data php occ config:system:get overwriteprotocol   # https
sudo -u www-data php occ config:system:get overwritehost       # cloud.example.com

# 2. Confirm URL generation uses the public URL
sudo -u www-data php -r '
require_once "/var/www/nextcloud/lib/base.php";
$url = \OC::$server->getURLGenerator()->linkToRouteAbsolute(
    "integration_suitecrm.config.oauthCallback"
);
echo $url . "\n";
'
# Expected output: https://cloud.example.com/apps/integration_suitecrm/oauth-callback

# 3. Run the app's built-in diagnostic
sudo -u www-data php occ integration_suitecrm:test-connection
```

If step 2 prints anything other than `https://cloud.example.com/...`, the overwrite trio isn't taking effect. Common causes:
- `overwritecondaddr` regex doesn't match the proxy's source IP (test with `journalctl -u nginx` or Apache access logs to find the real source IP the CLI sees)
- Config typo in `config.php` (run `php -l /var/www/nextcloud/config/config.php` to check syntax)

If step 2 is right but the OAuth flow still fails with `invalid_client`, the whitelisted redirect URL in the SuiteCRM OAuth2 Client is stale from a previous config — recreate it via the SuiteCRM admin UI with the URL from step 2.
