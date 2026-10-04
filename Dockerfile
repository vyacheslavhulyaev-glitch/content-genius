# syntax=docker/dockerfile:1

FROM node:24-bookworm-slim AS frontend-build
WORKDIR /build/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

FROM php:8.4-apache-bookworm AS php-runtime
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq5 libpq-dev \
    && docker-php-ext-install -j2 pdo_pgsql opcache \
    && apt-get purge -y --auto-remove libpq-dev $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers \
    && printf 'Listen 8080\n' > /etc/apache2/ports.conf \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --chmod=644 docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY --chmod=644 docker/php.ini /usr/local/etc/php/conf.d/contentgenius.ini
ENV APACHE_RUN_DIR=/tmp/apache2 \
    APACHE_PID_FILE=/tmp/apache2/apache2.pid \
    APACHE_LOCK_DIR=/tmp/apache2 \
    APP_ENV=production \
    APP_DEBUG=false
WORKDIR /var/www/html

FROM php-runtime AS backend-build
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-plugins
COPY app/ app/
COPY bootstrap/ bootstrap/
COPY config/ config/
COPY database/ database/
COPY public/ public/
COPY resources/ resources/
COPY routes/ routes/
COPY artisan ./
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts --no-plugins \
    && composer check-platform-reqs --no-dev

FROM php-runtime AS production
COPY --from=backend-build /var/www/html/ ./
COPY --from=frontend-build /build/frontend/dist/ public/
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/contentgenius-entrypoint
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R root:root /var/www/html \
    && chmod -R u=rwX,go=rX /var/www/html \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R u=rwX,g=rwX,o= storage bootstrap/cache
USER www-data
EXPOSE 8080
ENTRYPOINT ["contentgenius-entrypoint"]
CMD ["apache2-foreground"]
