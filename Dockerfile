FROM docker.io/mlocati/php-extension-installer:2.13@sha256:4fd45396c16685266d32bf147a24ecd5e81029d3820295895bf4a90131190ac9 AS php-extension-installer
FROM docker.io/library/composer:2.11@sha256:919efe8e62179c71dfd31fad035fd671fe7454211c6b2aa30a10af0ab4acedba AS composer

FROM docker.io/library/php:8.6.0RC7-fpm-alpine@sha256:008e80d23e87070347017eb8e400f7322dc8b5f4b99fa90220336b9ce3c16c3b AS runtime

COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN apk upgrade --no-cache libxml2 \
    && install-php-extensions apcu intl pcntl pdo_pgsql redis \
    && rm /usr/local/bin/install-php-extensions

WORKDIR /srv/app

COPY docker/php/conf.d/app.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY docker/php/php-fpm.d/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf
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
    && DATABASE_URL='postgresql://warmup@localhost:5432/warmup' REDIS_URL='redis://localhost:6379' php bin/console cache:warmup \
    && chmod -R go-w /srv/app


FROM runtime AS prod

ENV APP_ENV=prod APP_DEBUG=0
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/conf.d/prod.ini $PHP_INI_DIR/conf.d/zz-prod.ini

COPY --from=prod-builder /srv/app /srv/app

USER 82:82
