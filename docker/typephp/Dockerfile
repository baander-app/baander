FROM php:8.5.12-zts-trixie@sha256:2ffa2b7471a9826f8a28f722ff21f83bc560367c0135372d88697f6c0666b5eb

RUN apt-get update && apt-get install -y --no-install-recommends \
        autoconf bison build-essential ca-certificates cmake git pkg-config re2c unzip \
        libbrotli-dev libc-ares-dev libcurl4-openssl-dev libffi-dev libfreetype-dev \
        libgmp-dev libicu-dev libjpeg-dev liblz4-dev libmagickwand-dev libmpfr-dev \
        libonig-dev libpng-dev libpq-dev libreadline-dev libsodium-dev libsqlite3-dev libssl-dev \
        libuv1-dev libwebp-dev libxml2-dev libxpm-dev libyaml-dev libzip-dev \
        libzstd-dev zlib1g-dev \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.11.1@sha256:537fb03057ed901ae06bde7ef25af348903485ae1acc7fc789b80d8bac666f35 /usr/bin/composer /usr/local/bin/composer
COPY tools/typephp/composer.json tools/typephp/composer.lock /opt/typephp/
COPY tools/typephp/php-builder-object-directories.patch /opt/typephp/
COPY tools/typephp/php-builder-swoole-sources.patch /opt/typephp/
COPY tools/typephp/php-builder-runtime-lock.patch tools/typephp/runtime-lock.json /opt/typephp/
COPY tools/typephp/phpx-cli-script-dispatch.patch /opt/typephp/
COPY tools/typephp/phpx-file-access.patch /opt/typephp/
COPY tools/typephp/phpx-source-stream.patch /opt/typephp/
COPY tools/typephp/php-builder-redis-lzf.patch /opt/typephp/
COPY tools/typephp/php-builder-swoole-pdo-symbols.patch /opt/typephp/
COPY tools/typephp/php-builder-swoole-copy-result.patch /opt/typephp/
COPY tools/typephp/php-builder-qualification-bootstrap.patch /opt/typephp/
ENV TYPEPHP_RUNTIME_LOCK=/opt/typephp/runtime-lock.json
RUN composer --working-dir=/opt/typephp install --no-dev --no-scripts --no-plugins \
        --prefer-dist --no-interaction \
    && patch --directory=/opt/typephp/vendor/swoole/typephp --strip=1 < /opt/typephp/php-builder-object-directories.patch \
    && patch --directory=/opt/typephp/vendor/swoole/typephp --strip=1 < /opt/typephp/php-builder-swoole-sources.patch \
    && patch --directory=/opt/typephp/vendor/swoole/typephp --strip=1 < /opt/typephp/php-builder-runtime-lock.patch \
    && patch --directory=/opt/typephp/vendor/swoole/typephp --strip=1 < /opt/typephp/php-builder-redis-lzf.patch \
    && patch --directory=/opt/typephp/vendor/swoole/typephp --strip=1 < /opt/typephp/php-builder-swoole-pdo-symbols.patch \
    && patch --directory=/opt/typephp/vendor/swoole/typephp --strip=1 < /opt/typephp/php-builder-swoole-copy-result.patch \
    && patch --directory=/opt/typephp/vendor/swoole/typephp --strip=1 < /opt/typephp/php-builder-qualification-bootstrap.patch \
    && patch --directory=/opt/typephp/vendor/swoole/phpx --strip=1 < /opt/typephp/phpx-cli-script-dispatch.patch \
    && patch --directory=/opt/typephp/vendor/swoole/phpx --strip=1 < /opt/typephp/phpx-file-access.patch \
    && patch --directory=/opt/typephp/vendor/swoole/phpx --strip=1 < /opt/typephp/phpx-source-stream.patch \
    && php -r 'if (PHP_VERSION !== "8.5.12" || !PHP_ZTS || !extension_loaded("Zend OPcache")) { exit(1); }' \
    && php /opt/typephp/vendor/bin/tpc.php --version

ARG BUILDER_UID=1000
ARG BUILDER_GID=1000
RUN groupadd --gid "${BUILDER_GID}" typephp \
    && useradd --uid "${BUILDER_UID}" --gid "${BUILDER_GID}" --create-home typephp
USER typephp
WORKDIR /var/www/html
ENTRYPOINT ["php", "/opt/typephp/vendor/bin/tpc.php"]
