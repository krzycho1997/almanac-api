#!/bin/sh
set -eu

max_attempts=60
required_in_prod="APP_SECRET DATABASE_URL REDIS_URL NASA_API_KEY"

require_runtime_settings() {
    if [ "${APP_ENV:-dev}" != prod ]; then
        return
    fi

    missing=""
    for name in $required_in_prod; do
        if [ -z "$(printenv "$name" || true)" ]; then
            missing="$missing $name"
        fi
    done

    if [ -n "$missing" ]; then
        echo "entrypoint: set these environment variables:$missing" >&2
        exit 1
    fi
}

install_dependencies() {
    if [ "${APP_ENV:-dev}" != prod ]; then
        composer install --no-interaction --prefer-dist --no-progress
    fi
}

database_ready() {
    bin/console dbal:run-sql --quiet "SELECT 1" 2>&1
}

wait_for_database() {
    attempt=1
    until output=$(database_ready); do
        if [ "$attempt" -ge "$max_attempts" ]; then
            echo "entrypoint: database not ready after $max_attempts attempts:" >&2
            echo "$output" >&2
            exit 1
        fi
        echo "entrypoint: waiting for the database ($attempt/$max_attempts)" >&2
        attempt=$((attempt + 1))
        sleep 1
    done
}

migrate() {
    if [ -d migrations ]; then
        bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --all-or-nothing
    fi
}

if [ "$#" -eq 0 ] || [ "${1#-}" != "$1" ]; then
    set -- php-fpm "$@"
fi

if [ "$1" != php-fpm ]; then
    exec "$@"
fi

require_runtime_settings
install_dependencies
wait_for_database
migrate

exec "$@"
