# SuiteCRM 8.x dev container.
#
# We build from php:8.2-apache rather than pulling a community "suitecrm:*"
# image so that (a) the version is pinned to whatever the caller's live
# instance runs (default 8.10.1), and (b) we control the install script
# that seeds the OAuth2 client + OpenSSL keys.
#
# @Code Changes by: Kim Haverblad, 2026

FROM php:8.2-apache

ARG SUITECRM_VERSION=8.10.1

# ---- PHP extensions SuiteCRM 8 requires -----------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
        libxml2-dev libcurl4-openssl-dev libonig-dev libc-client-dev libkrb5-dev \
        libssl-dev libmagickwand-dev \
        default-mysql-client unzip curl ca-certificates rsync git openssl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath curl gd intl mbstring mysqli pdo_mysql soap xml zip imap opcache \
    && rm -rf /var/lib/apt/lists/*

# ---- Apache basics --------------------------------------------------------
RUN a2enmod rewrite headers
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# ---- PHP tuning appropriate for SuiteCRM 8 --------------------------------
RUN { \
      echo 'memory_limit=512M'; \
      echo 'upload_max_filesize=100M'; \
      echo 'post_max_size=100M'; \
      echo 'max_execution_time=180'; \
      echo 'date.timezone=UTC'; \
    } > /usr/local/etc/php/conf.d/suitecrm.ini

# ---- Fetch SuiteCRM release archive ---------------------------------------
WORKDIR /tmp
RUN curl -fsSL \
    "https://suitecrm.com/download/165/suite810/565107/suitecrm-${SUITECRM_VERSION}.zip" \
    -o suitecrm.zip \
 && unzip -q suitecrm.zip -d /var/www/html \
 && rm suitecrm.zip \
 && chown -R www-data:www-data /var/www/html

# ---- Entrypoint (installs SuiteCRM + seeds OAuth on first boot) -----------
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
COPY seed-oauth-client.sql /opt/seed/seed-oauth-client.sql
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
