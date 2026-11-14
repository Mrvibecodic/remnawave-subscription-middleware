FROM php:8.3-fpm-bookworm
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends nginx; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql; \
    php -r 'foreach (["pdo_sqlite", "pdo_mysql", "curl", "mbstring", "dom", "sodium", "openssl", "zlib"] as $e) if (!extension_loaded($e)) { fwrite(STDERR, "нет расширения PHP: $e\n"); exit(1); }'; \
    rm -rf /var/lib/apt/lists/* /var/www/html/index.nginx-debian.html
ARG SUBMW_VERSION=dev
ARG SUBMW_REF=
ENV SUBMW_DOCKER=1 SUBMW_VERSION=${SUBMW_VERSION} SUBMW_REF=${SUBMW_REF}
COPY --chown=www-data:www-data . /var/www/html
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN set -eux; \
    rm -rf /var/www/html/docker; \
    rm -f /etc/nginx/sites-enabled/default; \
    sed -i 's/^listen = .*/listen = 127.0.0.1:9000/' /usr/local/etc/php-fpm.d/zz-docker.conf; \
    { echo 'pm = ondemand'; echo 'pm.max_children = 8'; echo 'pm.process_idle_timeout = 10s'; echo 'pm.max_requests = 500'; } >> /usr/local/etc/php-fpm.d/zz-docker.conf; \
    printf 'display_errors = Off\ndisplay_startup_errors = Off\nlog_errors = On\n' > /usr/local/etc/php/conf.d/zz-submw.ini; \
    mkdir -p /var/www/html/data; \
    chown www-data:www-data /var/www/html/data; \
    chmod +x /entrypoint.sh
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD php -r '$c=@fsockopen("127.0.0.1",9000,$e,$s,2); exit($c?0:1);' || exit 1
ENTRYPOINT ["/entrypoint.sh"]
