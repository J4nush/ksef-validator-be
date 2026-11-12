FROM dunglas/frankenphp:1.13.5-php8.5.12-trixie@sha256:7e73a27eb7712a5b4073b92f8f0dec91e9400138604b76cc791589faaa6a639c AS base

RUN apt-get update \
    && apt-get upgrade -y \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions pcntl apcu bcmath

FROM base AS dev

RUN install-php-extensions zip pcov

# The rate limiter uses APCu, so tests need it in the CLI as well.
RUN echo 'apc.enable_cli=1' > "$PHP_INI_DIR/conf.d/zz-apcu-cli.ini"

COPY --from=composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 /usr/bin/composer /usr/local/bin/composer

FROM dev AS build

WORKDIR /app

RUN rm -rf /app/public

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MIRROR_PATH_REPOS=1

COPY composer.json composer.lock ./
COPY packages/ksef-fa3-validator packages/ksef-fa3-validator
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress

COPY artisan ./
COPY app app
COPY bootstrap bootstrap
COPY config config
COPY routes routes

RUN mkdir -p public storage/framework \
    && cp vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php public/ \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && php artisan route:cache \
    && rm -rf packages

FROM base AS prod

RUN rm -rf /app

ENV APP_ENV=production \
    APP_DEBUG=false \
    OPCACHE_VALIDATE_TIMESTAMPS=0 \
    APP_CONFIG_CACHE=/app/storage/framework/config.php \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && setcap -r /usr/local/bin/frankenphp \
    && useradd --system --uid 10001 --home-dir /nonexistent --shell /usr/sbin/nologin app

COPY docker/php/prod.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY --from=build /app /app

WORKDIR /app

USER app

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --start-interval=2s --retries=3 \
    CMD ["curl", "-fs", "-o", "/dev/null", "http://localhost:2019/metrics"]

CMD ["sh", "-c", "php artisan config:cache --no-ansi -q && exec php artisan octane:frankenphp --host=0.0.0.0 --port=8000 --admin-port=2019 --workers=2 --max-requests=500"]
