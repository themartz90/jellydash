FROM php:8.3.33-apache-bookworm@sha256:fa8852a2e01747ffe8c8768bfd6bbc2f296f974aa0de90ee157a66664996d263

ARG APP_ENV=production

ENV APP_ENV=${APP_ENV}
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        gosu \
        unzip \
        libcurl4-openssl-dev \
        libonig-dev \
        libgmp-dev \
    && docker-php-ext-install \
        curl \
        mbstring \
        mysqli \
        gmp \
    && php -r "exit(extension_loaded('curl') && extension_loaded('mbstring') && extension_loaded('mysqli') && extension_loaded('gmp') && extension_loaded('sqlite3') && extension_loaded('openssl') ? 0 : 1);" \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.10.3@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-progress --no-interaction --optimize-autoloader

COPY . .
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/app.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/entrypoint.sh /usr/local/bin/jellydash-entrypoint

RUN chmod +x /usr/local/bin/jellydash-entrypoint \
    && mkdir -p cache var/cache var/data var/log var/sessions public/uploads public/uploads/images \
    && chown -R www-data:www-data cache var/cache var/data var/log var/sessions public/uploads

ENTRYPOINT ["jellydash-entrypoint"]
HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=3 \
    CMD curl --fail --silent --show-error --max-time 2 http://127.0.0.1/healthz.php || exit 1
CMD ["apache2-foreground"]
