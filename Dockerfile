FROM dunglas/frankenphp:1-php8.5.12-bookworm AS base

RUN install-php-extensions pdo_pgsql intl zip opcache apcu

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    SERVER_NAME=":80"

COPY docker/php/app.ini $PHP_INI_DIR/conf.d/zz-app.ini

WORKDIR /app


FROM base AS vendor

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction


FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json .npmrc ./
COPY --from=vendor /app/vendor/symfony/ux-vue vendor/symfony/ux-vue
COPY --from=vendor /app/vendor/symfony/ux-turbo vendor/symfony/ux-turbo
RUN npm ci --no-audit --no-fund
COPY webpack.config.js ./
COPY assets assets
RUN npm run build


FROM base AS prod

ENV APP_ENV=prod \
    APP_DEBUG=0

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/prod.ini $PHP_INI_DIR/conf.d/zz-prod.ini
COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/php/docker-entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

COPY --from=vendor /app/vendor vendor
COPY . .
COPY --from=assets /app/public/build public/build

RUN composer dump-autoload --no-dev --classmap-authoritative \
    && composer dump-env prod \
    && composer run-script --no-dev post-install-cmd \
    && mkdir -p var/cache var/log var/share \
    && chown -R www-data:www-data var

ENTRYPOINT ["app-entrypoint"]
CMD ["--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]


FROM base AS dev
