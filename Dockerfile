FROM php:8.3-fpm-bookworm
ARG SUBMW_VERSION=dev
ENV SUBMW_DOCKER=1 SUBMW_VERSION=${SUBMW_VERSION}
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        nginx libcurl4-openssl-dev libsqlite3-dev libonig-dev libxml2-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_sqlite pdo_mysql curl mbstring dom; \
    rm -rf /var/lib/apt/lists/*
COPY . /var/www/html
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN set -eux; \
    rm -rf /var/www/html/.git /var/www/html/.github /var/www/html/docker \
           /var/www/html/install.sh /var/www/html/Dockerfile /var/www/html/.dockerignore; \
    rm -f /etc/nginx/sites-enabled/default; \
    mkdir -p /var/www/html/data; \
    chown -R www-data:www-data /var/www/html; \
    chmod +x /entrypoint.sh
EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]
