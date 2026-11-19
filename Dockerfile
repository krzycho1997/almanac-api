FROM docker.io/mlocati/php-extension-installer:2.13 AS php-extension-installer
FROM docker.io/library/composer:2.11 AS composer

FROM docker.io/library/php:8.5.12-fpm-alpine AS runtime

COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions apcu intl pdo_pgsql redis \
    && rm /usr/local/bin/install-php-extensions

WORKDIR /srv/app

COPY docker/php/conf.d/app.ini $PHP_INI_DIR/conf.d/zz-app.ini
RUN printf '[www]\naccess.log = /dev/null\n' > /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]


FROM runtime AS dev

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ENV APP_ENV=dev COMPOSER_ALLOW_SUPERUSER=1
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    && printf '[www]\nuser = root\ngroup = root\n' > /usr/local/etc/php-fpm.d/zz-dev.conf

CMD ["php-fpm", "--allow-to-run-as-root"]


FROM runtime AS prod-builder

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ENV APP_ENV=prod APP_DEBUG=0 COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && composer dump-env prod \
    && php bin/console cache:warmup \
    && chmod -R go-w /srv/app


FROM runtime AS prod

ENV APP_ENV=prod APP_DEBUG=0
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'expose_php = Off\nopcache.validate_timestamps = 0\nopcache.preload = /srv/app/config/preload.php\nopcache.preload_user = www-data\n' > "$PHP_INI_DIR/conf.d/zz-prod.ini"

COPY --from=prod-builder /srv/app /srv/app

USER 82:82
