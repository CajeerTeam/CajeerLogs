# Runtime does not require Composer or Node: PHP core and built SPA are shipped.
FROM php:8.4-fpm-trixie AS app
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libcurl4-openssl-dev postgresql-client-17 ca-certificates \
    && for ext in pdo_pgsql curl pcntl posix; do \
        if ! php -r "exit(extension_loaded('$ext') ? 0 : 1);"; then docker-php-ext-install -j"$(nproc)" "$ext" || exit 1; fi; \
    done \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY core ./core
COPY config ./config
COPY migrations ./migrations
COPY bin ./bin
COPY public ./public
COPY admin/index.html ./admin/index.html
COPY api ./api
COPY release.json openapi.yaml .env.example ./
ARG CAJEER_RELEASE_VERSION=0.9.0
# A retagged old image must fail at build-time, before it can be deployed.
RUN php -r '$d=json_decode(file_get_contents("release.json"),true,8,JSON_THROW_ON_ERROR); exit(($d["version"]??"")===getenv("CAJEER_RELEASE_VERSION") ? 0 : 1);'
COPY deploy/production/pool.conf /usr/local/etc/php-fpm.d/zz-cajeer.conf
COPY deploy/production/maintenance.sh /usr/local/bin/cajeer-maintenance
RUN mkdir -p /config /app/storage/app /app/storage/archives /app/storage/backups /app/storage/cache /app/storage/database /app/storage/logs /app/storage/tmp \
    && chown -R www-data:www-data /config /app/storage \
    && chmod 0700 /config /app/storage && chmod 0755 /usr/local/bin/cajeer-maintenance \
    && php -r 'exit(defined("PASSWORD_ARGON2ID") && extension_loaded("sodium") && extension_loaded("pdo_pgsql") && extension_loaded("curl") && extension_loaded("pcntl") && extension_loaded("posix") ? 0 : 1);'
USER www-data
CMD ["php-fpm", "-F"]

FROM nginx:stable-alpine AS gateway
COPY deploy/production/nginx.conf /etc/nginx/conf.d/default.conf
COPY --chmod=0755 deploy/production/gateway-origin.sh /docker-entrypoint.d/40-cajeer-origin.sh
COPY public/admin/assets /app/public/admin/assets
COPY public/assets /app/public/assets
