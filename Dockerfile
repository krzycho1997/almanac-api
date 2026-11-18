FROM docker.io/mlocati/php-extension-installer:2.13 AS php-extension-installer
FROM docker.io/library/composer:2.11 AS composer

FROM docker.io/library/php:8.5.12-fpm-alpine AS base

COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN install-php-extensions apcu intl pdo_pgsql redis

ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /srv/app

COPY docker/php/conf.d/app.ini $PHP_INI_DIR/conf.d/zz-app.ini


FROM base AS dev

ENV APP_ENV=dev
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    && printf '[www]\nuser = root\ngroup = root\n' > /usr/local/etc/php-fpm.d/zz-dev.conf

CMD ["php-fpm", "--allow-to-run-as-root"]


FROM base AS prod

ENV APP_ENV=prod APP_DEBUG=0
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'expose_php = Off\nopcache.validate_timestamps = 0\n' > "$PHP_INI_DIR/conf.d/zz-prod.ini"

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

COPY --chown=www-data:www-data . .
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && php bin/console cache:warmup \
    && chown -R www-data:www-data var

USER www-data
