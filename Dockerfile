# =============================================================================
# Multi-stage Dockerfile for Baander
# Stages: base -> {builder-php, builder-swoole, builder-tsduck} -> builder -> runtime -> {dev, production}
#
# Heavy build steps (Swoole, TSDuck) run in parallel via BuildKit.
# Requires BuildKit for cache mounts and parallel stages: DOCKER_BUILDKIT=1
# =============================================================================

# -----------------------------------------------------------------------------
# Stage 1: base
# System dependencies and Node.js.
# Provides the common foundation for both builder and runtime.
# FFmpeg comes from the project's static image (docker/ffmpeg/Dockerfile),
# selected through the FFMPEG_IMAGE build argument that the tooling fills
# from its image catalog. Every external image is pinned by digest and every
# downloaded source by SHA-256 or commit.
# -----------------------------------------------------------------------------
ARG OPERATOR_HELPER_IMAGE=scratch
ARG FFMPEG_IMAGE=scratch
ARG LIBURING_VERSION=2.13
ARG LIBURING_SHA256=618e34dbea408fc9e33d7c4babd746036dbdedf7fce2496b1178ced0f9b5b357
FROM ${FFMPEG_IMAGE} AS ffmpeg-dist

FROM node:24.22.2-trixie-slim@sha256:0820eaa85e07d0490446dd01d14707e17e6108a36988cf374899cd057eb3812d AS node-dist

FROM php:8.5.12-zts-trixie@sha256:2ffa2b7471a9826f8a28f722ff21f83bc560367c0135372d88697f6c0666b5eb AS base

ARG TARGETPLATFORM

# Node.js keeps the layout of Debian's nodejs package, so /usr/bin/node and the
# global npm and corepack under /usr/lib/node_modules stay where tooling expects them.
COPY --from=node-dist /usr/local/bin/node /usr/bin/node
COPY --from=node-dist /usr/local/lib/node_modules /usr/lib/node_modules
COPY --from=node-dist /usr/local/include/node /usr/include/node
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/bin/npm && \
    ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/bin/npx && \
    ln -s ../lib/node_modules/corepack/dist/corepack.js /usr/bin/corepack && \
    node --version

# Install system dependencies.
# --mount=type=cache caches APT indices and packages across rebuilds.
RUN set -xe && \
    DEBIAN_FRONTEND=noninteractive apt-get update -qq && \
    DEBIAN_FRONTEND=noninteractive apt-get upgrade -yqq && \
    DEBIAN_FRONTEND=noninteractive apt-get install -yqq -o=Dpkg::Use-Pty=0 \
        build-essential \
        git \
        python3 \
        libyaml-dev \
        ca-certificates \
        bison \
        cron \
        curl \
        wget \
        nano \
        procps \
        sudo \
        gnupg \
        supervisor \
        unzip \
        iputils-ping \
        libavif-dev \
        libbrotli-dev \
        libbz2-dev \
        libc-ares-dev \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libgmp-dev \
        libicu-dev \
        libjpeg-dev \
        libjpeg62-turbo-dev \
        liblz4-dev \
        libmagickwand-dev \
        libpng-dev \
        libpq-dev \
        libreadline-dev \
        libsqlite3-dev \
        libssh2-1-dev \
        libwebp-dev \
        libxml2-dev \
        libxpm-dev \
        libzip-dev \
        libzstd-dev \
        libuv1-dev \
        postgresql-client \
        redis-tools \
        jq \
        tree \
        zlib1g-dev && \
    corepack enable

# TSDuck build dependencies (needed by builder stages)
RUN set -xe && \
    DEBIAN_FRONTEND=noninteractive apt-get update -qq && \
    DEBIAN_FRONTEND=noninteractive apt-get install -yqq -o=Dpkg::Use-Pty=0 \
        g++ \
        make \
        cmake \
        locales-all \
        flex \
        bison \
        dos2unix \
        zip \
        dpkg-dev \
        openssl \
        libssl-dev \
        libedit-dev \
        pcscd \
        libpcsclite-dev \
        libcurl4-openssl-dev \
        librist-dev \
        libsrt-openssl-dev \
        libusb-1.0-0-dev

# The project's FFmpeg build replaces Debian's package at the path the native
# execution daemon allows; its shared-library needs come from the list the
# static image derived from the built binaries.
ARG FFMPEG_IMAGE
RUN test "$FFMPEG_IMAGE" != scratch || { echo 'FFMPEG_IMAGE is required. Use php scripts/build-application.php, which fills it from the tooling image catalog.' >&2; exit 1; }
COPY --from=ffmpeg-dist /usr/local/bin/ffmpeg /usr/local/bin/ffprobe /usr/bin/
COPY docker/ffmpeg/runtime-packages.txt /opt/baander/ffmpeg-runtime-packages.txt
RUN set -xe && \
    DEBIAN_FRONTEND=noninteractive apt-get update -qq && \
    grep -v '^#' /opt/baander/ffmpeg-runtime-packages.txt | DEBIAN_FRONTEND=noninteractive xargs apt-get install -yqq -o=Dpkg::Use-Pty=0 && \
    rm -rf /var/lib/apt/lists/*

# -----------------------------------------------------------------------------
# Stage 2a: builder-php
# PHP core extensions, liburing, PECL extensions, and Composer.
# Runs in parallel with builder-swoole and builder-tsduck.
# -----------------------------------------------------------------------------
FROM base AS builder-php

ARG LIBURING_VERSION
ARG LIBURING_SHA256

# Configure PHP extensions
RUN set -xe && \
    docker-php-ext-configure gd --with-webp --with-jpeg --with-xpm --with-freetype && \
    docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql && \
    docker-php-ext-configure intl

# Install PHP core extensions.
# --mount=type=cache speeds up rebuilds by caching compiler objects.
RUN set -xe && \
    docker-php-ext-install -j "$(nproc)" \
      exif \
      ffi \
      gd \
      gettext \
      gmp \
      intl \
      pcntl \
      pdo_pgsql \
      pgsql \
      sockets \
      zip \
      shmop \
      sysvmsg

# Compile liburing from source.
# --mount=type=cache preserves the build directory across rebuilds.
COPY scripts/retain-build-evidence.php /usr/local/bin/retain-build-evidence.php
RUN set -xe && \
    curl -fsSL "https://github.com/axboe/liburing/archive/refs/tags/liburing-${LIBURING_VERSION}.tar.gz" -o "liburing-${LIBURING_VERSION}.tar.gz" && \
    echo "${LIBURING_SHA256}  liburing-${LIBURING_VERSION}.tar.gz" | sha256sum -c - && \
    tar zxf liburing-${LIBURING_VERSION}.tar.gz && \
    cd liburing-liburing-${LIBURING_VERSION} && \
    ./configure && \
    make -j$(nproc) install && \
    cd .. && \
    php /usr/local/bin/retain-build-evidence.php collect \
        --output /tmp/build-evidence-php --id liburing --name liburing \
        --version "${LIBURING_VERSION}" --source-dir "liburing-liburing-${LIBURING_VERSION}" \
        --source-url "https://github.com/axboe/liburing/archive/refs/tags/liburing-${LIBURING_VERSION}.tar.gz" \
        --source-archive "liburing-${LIBURING_VERSION}.tar.gz" \
        --artifact '/usr/lib/liburing*' && \
    rm -rf "liburing-liburing-${LIBURING_VERSION}" && rm -f "liburing-${LIBURING_VERSION}.tar.gz"

# Install PECL extensions from release archives pinned by version and SHA-256.
# Each archive holds package.xml beside a <name>-<version>/ source directory;
# both land in /tmp/pecl/<name>, the layout `pecl bundle` produced.
# --mount=type=cache,target=/tmp/pecl keeps the build tree out of the layer.
RUN --mount=type=cache,target=/tmp/pecl \
    set -e \
    && for archive in \
        'imagick 3.8.1 3a3587c0a524c17d0dad9673a160b90cd776e836838474e173b549ed864352ee' \
        'igbinary 3.2.17RC1 91da821443db125282a6aea039f24588dd28ff5d71e8187f6ecc41165bceafbc' \
        'redis 6.3.0 0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5' \
        'mailparse 3.2.0 cc5111ae17bfa36efcc5ef23dcf75b6501593ac264c196aad3e39cd4ad765332' \
        'inotify 3.0.1 fbb7240abae502ea8f4b3c68264c6425fb46647402a8f93916482845394ad124' \
        'excimer 1.2.6 7b5fe1f68f2b1a62bd0394d4bf165eafe6b7ceb3fc20ab885e733d356db0d034' \
        'yaml 2.3.0 bc8404807a3a4dc896b310af21a7f8063aa238424ff77f27eb6ffa88b5874b8a' \
        'uv 0.3.1 1b18d961543f76eebae300893987b3f198874d9f039cf472ac6257f85a28db77'; do \
        set -- $archive; \
        rm -rf "/tmp/pecl/$1" "/tmp/pecl/$1.tgz"; \
        curl -fsSL "https://pecl.php.net/get/$1-$2.tgz" -o "/tmp/pecl/$1.tgz"; \
        echo "$3  /tmp/pecl/$1.tgz" | sha256sum -c -; \
        mkdir "/tmp/pecl/$1"; \
        tar -xzf "/tmp/pecl/$1.tgz" -C "/tmp/pecl/$1" --strip-components=1 "$1-$2"; \
        tar -xzf "/tmp/pecl/$1.tgz" -C "/tmp/pecl/$1" package.xml; \
    done \
    && set -x \
    # imagick
    && docker-php-ext-configure /tmp/pecl/imagick \
    && docker-php-ext-install -j$(nproc) /tmp/pecl/imagick \
    # igbinary
    && docker-php-ext-configure /tmp/pecl/igbinary \
    && docker-php-ext-install -j$(nproc) /tmp/pecl/igbinary \
    # redis
    && docker-php-ext-configure /tmp/pecl/redis --enable-redis-igbinary --enable-redis-lzf --enable-redis-zstd --with-liblz4 \
    && docker-php-ext-install -j$(nproc) /tmp/pecl/redis \
    # mailparse
    && docker-php-ext-configure /tmp/pecl/mailparse \
    && docker-php-ext-install -j$(nproc) /tmp/pecl/mailparse \
    # inotify
    && docker-php-ext-configure /tmp/pecl/inotify \
    && docker-php-ext-install -j$(nproc) /tmp/pecl/inotify \
    # excimer
    && docker-php-ext-configure /tmp/pecl/excimer \
    && docker-php-ext-install -j$(nproc) /tmp/pecl/excimer \
    # yaml
    && docker-php-ext-configure /tmp/pecl/yaml \
    && docker-php-ext-install -j$(nproc) /tmp/pecl/yaml \
    # uv
    && docker-php-ext-configure /tmp/pecl/uv \
    && docker-php-ext-install -j$(nproc) /tmp/pecl/uv \
    && for extension in imagick igbinary redis mailparse inotify excimer yaml uv; do \
        php /usr/local/bin/retain-build-evidence.php collect \
            --output /tmp/build-evidence-php --id "pecl-${extension}" --name "${extension}" \
            --source-dir "/tmp/pecl/${extension}" --package-xml "/tmp/pecl/${extension}/package.xml" \
            --source-url "https://pecl.php.net/package/${extension}" --source-archive "/tmp/pecl/${extension}.tgz" \
            --artifact "/usr/local/lib/php/extensions/*/${extension}.so"; \
    done

RUN set -xe && \
    docker-php-source extract && \
    php /usr/local/bin/retain-build-evidence.php collect \
        --output /tmp/build-evidence-php --id php --name PHP \
        --version "${PHP_VERSION}" --source-dir /usr/src/php \
        --source-url "${PHP_URL}" --source-archive /usr/src/php.tar.xz \
        --artifact '/usr/local/bin/php*' \
        --artifact /usr/local/lib/libphp.so \
        --artifact '/usr/local/lib/php/extensions/*/exif.so' \
        --artifact '/usr/local/lib/php/extensions/*/ffi.so' \
        --artifact '/usr/local/lib/php/extensions/*/gd.so' \
        --artifact '/usr/local/lib/php/extensions/*/gettext.so' \
        --artifact '/usr/local/lib/php/extensions/*/gmp.so' \
        --artifact '/usr/local/lib/php/extensions/*/intl.so' \
        --artifact '/usr/local/lib/php/extensions/*/pcntl.so' \
        --artifact '/usr/local/lib/php/extensions/*/pdo_pgsql.so' \
        --artifact '/usr/local/lib/php/extensions/*/pgsql.so' \
        --artifact '/usr/local/lib/php/extensions/*/sockets.so' \
        --artifact '/usr/local/lib/php/extensions/*/zip.so' \
        --artifact '/usr/local/lib/php/extensions/*/shmop.so' \
        --artifact '/usr/local/lib/php/extensions/*/sysvmsg.so' \
        --artifact '/usr/local/lib/php/extensions/*/sodium.so' && \
    docker-php-source delete

# Install Composer from official image
COPY --from=composer:2.11.1@sha256:537fb03057ed901ae06bde7ef25af348903485ae1acc7fc789b80d8bac666f35 /usr/bin/composer /usr/bin/composer
RUN chmod +x /usr/bin/composer

# -----------------------------------------------------------------------------
# Stage 2b: builder-swoole
# Swoole extension with io_uring support.
# Builds liburing first (small, ~30s) then Swoole.
# Runs in parallel with builder-php and builder-tsduck.
# -----------------------------------------------------------------------------
FROM base AS builder-swoole

ARG LIBURING_VERSION
ARG LIBURING_SHA256
ARG SWOOLE_VERSION=6.2.4
ARG SWOOLE_SHA256=cb41348708cb3a360672cd4ffe84b9a6b4efee61773be7d76a11c39949b95334

# Compile liburing (duplicated — avoids cross-stage dependency, ~30s build)
RUN set -xe && \
    curl -fsSL "https://github.com/axboe/liburing/archive/refs/tags/liburing-${LIBURING_VERSION}.tar.gz" -o "liburing-${LIBURING_VERSION}.tar.gz" && \
    echo "${LIBURING_SHA256}  liburing-${LIBURING_VERSION}.tar.gz" | sha256sum -c - && \
    tar zxf liburing-${LIBURING_VERSION}.tar.gz && \
    cd liburing-liburing-${LIBURING_VERSION} && \
    ./configure && \
    make -j$(nproc) install && \
    cd .. && \
    rm -rf "liburing-liburing-${LIBURING_VERSION}" && rm -f "liburing-${LIBURING_VERSION}.tar.gz"

# Install sockets extension (required by Swoole's --enable-sockets)
RUN set -xe && \
    docker-php-ext-install -j "$(nproc)" sockets

# Match the exact native API required by Composer and the Swoole bundle stubs.
# Install Swoole from its PECL release archive with custom flags.
# Keep the async thread file backend: io_uring requires syscalls denied by the
# deployment container's default seccomp profile and has no runtime fallback.
COPY scripts/retain-build-evidence.php /usr/local/bin/retain-build-evidence.php
RUN set -xe && \
    curl -fsSL "https://pecl.php.net/get/swoole-${SWOOLE_VERSION}.tgz" -o /tmp/swoole.tgz && \
    echo "${SWOOLE_SHA256}  /tmp/swoole.tgz" | sha256sum -c - && \
    mkdir /tmp/swoole && \
    tar -xzf /tmp/swoole.tgz -C /tmp/swoole --strip-components=1 "swoole-${SWOOLE_VERSION}" && \
    tar -xzf /tmp/swoole.tgz -C /tmp/swoole package.xml && \
    php /usr/local/bin/retain-build-evidence.php collect \
        --output /tmp/build-evidence-swoole --id swoole --name Swoole \
        --source-dir /tmp/swoole --package-xml /tmp/swoole/package.xml \
        --source-url "https://pecl.php.net/get/swoole-${SWOOLE_VERSION}.tgz" --source-archive /tmp/swoole.tgz \
        --artifact '/usr/local/lib/php/extensions/*/swoole.so' && \
    docker-php-ext-configure /tmp/swoole \
        --enable-swoole-thread \
        --enable-sockets \
        --enable-swoole-curl \
        --enable-cares \
        --enable-swoole-pgsql \
        --enable-swoole-sqlite \
        --enable-zstd && \
    docker-php-ext-install -j "$(nproc)" /tmp/swoole && \
    php -r 'if (phpversion("swoole") !== $argv[1]) { exit(1); }' "${SWOOLE_VERSION}" && \
    rm -rf /tmp/swoole /tmp/swoole.tgz

# -----------------------------------------------------------------------------
# Stage 2c: builder-tsduck
# TSDuck MPEG-TS toolkit (C++ library + CLI tools for PHP FFI bindings).
# Runs in parallel with builder-php and builder-swoole.
# -----------------------------------------------------------------------------
FROM base AS builder-tsduck

ARG TSDUCK_VERSION=v3.43-4549
ARG TSDUCK_COMMIT=a88644421b7a576fce1e9fde412459bc5cde6d43

# Build TSDuck from source (shared library + CLI tools for PHP FFI bindings)
RUN set -xe && \
    git clone --depth 1 --branch ${TSDUCK_VERSION} https://github.com/tsduck/tsduck.git /tmp/tsduck-src && \
    test "$(git -C /tmp/tsduck-src rev-parse HEAD)" = "${TSDUCK_COMMIT}" && \
    cd /tmp/tsduck-src && \
    make -j$(nproc) NOSTATIC=true NOJAVA=true NOPYTHON=true NOPHP=true CXXFLAGS_EXTRA="-Wno-restrict -Wno-deprecated-copy" && \
    make -j$(nproc) NOTEST=true -C src install-tools && \
    ldconfig

COPY scripts/retain-build-evidence.php /usr/local/bin/retain-build-evidence.php
RUN set -e; \
    set --; \
    for source in /tmp/tsduck-src/src/tstools/ts*.cpp; do \
        tool="/usr/bin/$(basename "$source" .cpp)"; \
        if [ -f "$tool" ]; then set -- "$@" --artifact "$tool"; fi; \
    done; \
    php /usr/local/bin/retain-build-evidence.php collect \
    --output /tmp/build-evidence-tsduck --id tsduck --name TSDuck \
    --version "${TSDUCK_VERSION}" --source-dir /tmp/tsduck-src \
    --source-url https://github.com/tsduck/tsduck.git \
    --source-revision "$(git -C /tmp/tsduck-src rev-parse HEAD)" \
    --artifact '/usr/lib/libtsduck*' --artifact '/usr/lib/libtscore*' "$@"

# -----------------------------------------------------------------------------
# Stage 3: builder
# Merges compiled artifacts from all parallel builder stages.
# Fast — only COPY operations, no compilation.
# -----------------------------------------------------------------------------
FROM base AS builder

# Merge PHP extensions from builder-php and builder-swoole
COPY --from=builder-php /usr/local/lib/php/ /usr/local/lib/php/
COPY --from=builder-php /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY --from=builder-swoole /usr/local/lib/php/ /usr/local/lib/php/
COPY --from=builder-swoole /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# Copy liburing shared libraries
COPY --from=builder-php /usr/local/lib/liburing* /usr/local/lib/
COPY --from=builder-php /usr/lib/liburing* /usr/lib/

# Copy TSDuck shared libraries and CLI tools
COPY --from=builder-tsduck /usr/lib/libtsduck* /usr/lib/
COPY --from=builder-tsduck /usr/lib/libtscore* /usr/lib/
COPY --from=builder-tsduck /usr/bin/ts* /usr/bin/

# Copy Composer
COPY --from=builder-php /usr/bin/composer /usr/bin/composer

# Update linker cache for copied libraries
RUN ldconfig

FROM builder AS application-evidence

COPY scripts/retain-build-evidence.php /usr/local/bin/retain-build-evidence.php
COPY --from=builder-php /tmp/build-evidence-php /tmp/build-evidence-php
COPY --from=builder-swoole /tmp/build-evidence-swoole /tmp/build-evidence-swoole
COPY --from=builder-tsduck /tmp/build-evidence-tsduck /tmp/build-evidence-tsduck
RUN php /usr/local/bin/retain-build-evidence.php phar \
        --output /tmp/build-evidence-tools --id composer --name Composer \
        --archive /usr/bin/composer --artifact /usr/bin/composer && \
    php /usr/local/bin/retain-build-evidence.php finalize \
        --output /usr/share/baander-build-evidence \
        --input /tmp/build-evidence-php --input /tmp/build-evidence-swoole \
        --input /tmp/build-evidence-tsduck --input /tmp/build-evidence-tools

# -----------------------------------------------------------------------------
# Stage 4: runtime
# Adds compiled artifacts and runtime libraries to the common base.
# Build tools and development libraries installed in base remain in this image.
# -----------------------------------------------------------------------------
FROM base AS runtime

# Runtime ARGs
ARG HOST_UID=1000
ARG HOST_GID=1000
ARG INSIDE_DOCKER_CONTAINER=1

ARG APP_HOME
RUN test -n "$APP_HOME" || { echo 'APP_HOME is required. Use php scripts/build-application.php --target production --tag baander:local' >&2; exit 1; }
ENV APP_HOME=${APP_HOME} \
    USERNAME=www-data \
    INSIDE_DOCKER_CONTAINER=$INSIDE_DOCKER_CONTAINER \
    COMPOSER_ALLOW_SUPERUSER=1

# Install additional runtime library packages.
RUN set -xe && \
    DEBIAN_FRONTEND=noninteractive apt-get update -qq && \
    DEBIAN_FRONTEND=noninteractive apt-get install -yqq -o=Dpkg::Use-Pty=0 \
        ca-certificates \
        cron \
        curl \
        wget \
        nano \
        procps \
        sudo \
        gnupg \
        supervisor \
        unzip \
        iputils-ping \
        postgresql-client \
        redis-tools \
        jq \
        tree \
        git \
        # Runtime library packages for compiled extensions
        libpq5 \
        libssl3t64 \
        libmagickwand-7.q16-10 \
        libjpeg62-turbo \
        libpng16-16t64 \
        libwebp7 \
        libxpm4 \
        libfreetype6 \
        libgmp10 \
        libzip5 \
        libbrotli1 \
        liblz4-1 \
        libzstd1 \
        zlib1g \
        libcurl4t64 \
        libxml2 \
        libicu76 \
        libsqlite3-0 \
        libyaml-0-2 \
        libuv1t64 \
        libc-ares2 \
        libssh2-1t64 \
        # libavif runtime
        libavif16 \
        # TSDuck runtime dependencies
        libedit2 \
        libpcsclite1 \
        librist4 \
        libsrt1.5-openssl \
        # Intel QSV / VAAPI hardware transcoding; Mesa's VAAPI driver
        # serves AMD GPUs
        libva2 \
        libdrm2 \
        mesa-va-drivers \
        vainfo \
        && rm -rf /var/lib/apt/lists/* && \
    apt-get clean

# The project FFmpeg must list every accelerator and hardware encoder the
# application can select and resolve all of its shared objects here, where the
# runtime libraries are installed.
COPY scripts/ffmpeg-capability-gate.sh /usr/local/bin/ffmpeg-capability-gate
COPY docker/ffmpeg/expected-capabilities.txt /opt/baander/ffmpeg-expected-capabilities.txt
RUN chmod +x /usr/local/bin/ffmpeg-capability-gate && \
    ffmpeg-capability-gate /usr/bin/ffmpeg /opt/baander/ffmpeg-expected-capabilities.txt

# Copy compiled PHP extensions (entire directory tree)
COPY --from=builder /usr/local/lib/php/ /usr/local/lib/php/

# Copy extension .ini configuration files
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# Standalone images carry the CLI opcode cache settings; development mounts
# may replace php.ini without disabling the shared runtime configuration.
RUN cp /usr/local/etc/php/php.ini-development /usr/local/etc/php/php.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/baander-opcache.ini
# conf.d is read after php.ini, so the URL wrappers stay off under whichever
# php.ini an image built on this stage installs or a deployment mounts.
COPY docker/php/url-wrappers.ini /usr/local/etc/php/conf.d/baander-url-wrappers.ini
RUN php -r '\
    $probe = tempnam(sys_get_temp_dir(), "baander-opcache-"); \
    try { \
        file_put_contents($probe, "<?php return 42;\n"); \
        touch($probe, time() - 10); \
        if (!(opcache_get_status(false)["opcache_enabled"] ?? false) \
            || !opcache_compile_file($probe) || !opcache_is_script_cached($probe)) { \
            throw new RuntimeException("CLI OPcache must cache scripts in the runtime image."); \
        } \
    } finally { unlink($probe); }'

# Copy compiled liburing shared libraries
COPY --from=builder /usr/local/lib/liburing* /usr/local/lib/
COPY --from=builder /usr/lib/liburing* /usr/lib/

# Copy TSDuck shared libraries and CLI tools
COPY --from=builder /usr/lib/libtsduck* /usr/lib/
COPY --from=builder /usr/lib/libtscore* /usr/lib/
COPY --from=builder /usr/bin/ts* /usr/bin/

# Update linker cache for copied libraries
RUN ldconfig

# The log agent indexes retained records and reads gzip/ZIP archives.
RUN php -r 'foreach (["curl", "mbstring", "sqlite3", "xml", "zip", "zlib"] as $name) { if (!extension_loaded($name)) { throw new RuntimeException("Missing log viewer extension: " . $name); } } $db = new SQLite3(":memory:"); $db->exec("CREATE VIRTUAL TABLE logs USING fts5(message, tokenize=trigram)");'

# Set up user permissions
RUN set -xe && \
    mkdir -p ${APP_HOME}/public && \
    mkdir -p /home/${USERNAME} && \
    chown ${USERNAME}:${USERNAME} /home/${USERNAME} && \
    usermod -o -u ${HOST_UID} ${USERNAME} -d /home/${USERNAME} && \
    groupmod -o -g ${HOST_GID} ${USERNAME} && \
    chown -R ${USERNAME}:${USERNAME} ${APP_HOME}

# Web and worker roles run as foreground container processes.
COPY --chmod=755 ./docker/general/start-web.sh ./docker/general/start-worker.sh ./docker/general/start-operator.sh /usr/local/bin/

# Set working directory
WORKDIR ${APP_HOME}

# Switch to app user
USER ${USERNAME}

CMD ["/usr/local/bin/start-web.sh"]

# -----------------------------------------------------------------------------
# Stage 5: dev
# Development target. Adds Xdebug, CA certificates, and debug aliases.
# Designed for use with docker-compose volume mount workflow.
# -----------------------------------------------------------------------------
FROM runtime AS dev

ENV APP_ENV=dev

ARG HOST_UID=1000
ARG HOST_GID=1000
ARG XDEBUG_CONFIG=main
ARG XDEBUG_VERSION=3.5.0
ARG XDEBUG_SHA256=f6daf55a5c7adadb07dd2af25ff78b1cc9b1c58d6cc442a463eba015b678aabf

ENV XDEBUG_CONFIG=$XDEBUG_CONFIG \
    XDEBUG_VERSION=$XDEBUG_VERSION

# Switch to root for setup
USER root

# Copy Composer from builder
COPY --from=builder /usr/bin/composer /usr/bin/composer

# Install the release archive pinned by version and SHA-256.
# --mount=type=cache,target=/tmp/pecl keeps the build tree out of the layer.
RUN --mount=type=cache,target=/tmp/pecl \
    set -xe && \
    rm -rf /tmp/pecl/xdebug /tmp/pecl/xdebug.tgz && \
    curl -fsSL "https://pecl.php.net/get/xdebug-${XDEBUG_VERSION}.tgz" -o /tmp/pecl/xdebug.tgz && \
    echo "${XDEBUG_SHA256}  /tmp/pecl/xdebug.tgz" | sha256sum -c - && \
    mkdir /tmp/pecl/xdebug && \
    tar -xzf /tmp/pecl/xdebug.tgz -C /tmp/pecl/xdebug --strip-components=1 "xdebug-${XDEBUG_VERSION}" \
    && docker-php-ext-configure /tmp/pecl/xdebug \
    && docker-php-ext-install -j$(nproc) /tmp/pecl/xdebug

# Copy runtime entrypoint (disables Xdebug before supervisord starts)
COPY --chmod=755 ./docker/dev/entrypoint.sh /usr/local/bin/entrypoint.sh

# Copy Xdebug config directly to the correct location (one step)
COPY ./docker/dev/xdebug-main.ini /usr/local/etc/php/conf.d/xdebug.ini

# Copy Swoole config (runtime settings only — extension= is in the docker-php-ext-swoole.ini that docker-php-ext-install wrote)
COPY ./docker/dev/swoole-runtime.ini /usr/local/etc/php/conf.d/swoole-runtime.ini

# Set up HOST_UID/HOST_GID remapping
RUN set -xe && \
    usermod -o -u ${HOST_UID} www-data && \
    groupmod -o -g ${HOST_GID} www-data

# Set up bash aliases
RUN echo 'alias sf="php ${APP_HOME}/bin/console"' >> /home/www-data/.bashrc && \
    echo 'alias sf-dev="php ${APP_HOME}/bin/console app:serve"' >> /home/www-data/.bashrc && \
    echo '' >> /home/www-data/.bashrc && \
    echo '# ----- Xdebug quick toggle (entrypoint disables it at boot) -----' >> /home/www-data/.bashrc && \
    echo 'alias debug-on="sed -i \"s/^;zend_extension/zend_extension/\" /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini; sed -i \"s/^xdebug\\.mode.*/xdebug.mode = debug,develop/\" /usr/local/etc/php/conf.d/xdebug.ini; echo \"Xdebug ON — restart Swoole or start a new PHP process to activate\""' >> /home/www-data/.bashrc && \
    echo 'alias debug-off="sed -i \"s/^zend_extension/;zend_extension/\" /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini; sed -i \"s/^xdebug\\.mode.*/xdebug.mode = off/\" /usr/local/etc/php/conf.d/xdebug.ini; echo \"Xdebug OFF — restart Swoole or start a new PHP process to take effect\""' >> /home/www-data/.bashrc

# Make xdebug conf.d directory and ini files writable by www-data (for entrypoint sed toggle)
RUN chown www-data:www-data /usr/local/etc/php/conf.d && \
    chown www-data:www-data /usr/local/etc/php/conf.d/xdebug.ini /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

# Switch back to app user
USER www-data

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/local/bin/start-web.sh"]

# -----------------------------------------------------------------------------
# Stage 6: production
# Production target. Copies source and installs Composer dependencies.
# Designed for standalone use (CI deploys, image registry pushes).
# When used with docker-compose.yml volume mount, host files overlay this.
# -----------------------------------------------------------------------------
FROM runtime AS production

ARG BAANDER_SOURCE_REVISION
LABEL org.opencontainers.image.revision="${BAANDER_SOURCE_REVISION}"

ENV APP_ENV=prod APP_DEBUG=0

# Switch to root to install the Composer executable.
USER root

# Copy Composer from builder (already installed there)
COPY --from=builder /usr/bin/composer /usr/bin/composer
COPY --from=application-evidence /usr/share/baander-build-evidence /usr/share/baander-build-evidence

# Path repositories, patches and application classes must exist before Composer
# resolves local packages and generates the optimized application autoloader.
COPY --chown=www-data:www-data . ${APP_HOME}/
COPY --chown=www-data:www-data .env.example ${APP_HOME}/.env

# Standalone production must not inherit development error-display settings.
# Never ship a cache generated in the build context's environment.
# Every PHP process in the image reads the same memory_limit from conf.d: the web server's
# memory check measures each worker against it, and the HEALTHCHECK CLI reports against it
# too. php.ini-production's default of 128M is too small for a web worker. The worker
# supervisor passes its own limits to its children with -d.
RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini && \
    printf 'memory_limit=512M\n' > /usr/local/etc/php/conf.d/zz-baander-production-memory.ini && \
    rm -rf var/cache && \
    mkdir -p var/cache var/log && \
    chown www-data:www-data var var/cache var/log

# Dependency files and any later cache/assets generation belong to the app user.
USER www-data

# Auto-scripts boot Symfony for cache clearing and asset installation. Defer them
# until runtime configuration is available instead of warming a build-time kernel.
# A separate dump runs the Runtime plugin's autoload hook without post-install
# auto-scripts, so bin/console still receives vendor/autoload_runtime.php.
RUN COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts && \
    COMPOSER_MEMORY_LIMIT=-1 composer dump-autoload --no-dev --optimize --no-interaction && \
    COMPOSER_MEMORY_LIMIT=-1 composer --working-dir=scripts install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts --no-plugins && \
    rm -rf /home/www-data/.cache/composer packages/baander-dev-* packages/baander-*/tests tests/Support/Tooling tests/Tooling scripts/tests

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD ["php", "bin/console", "app:health:check"]

CMD ["/usr/local/bin/start-web.sh"]

# The trusted deployment controller supplies immutable identity, explicit budgets
# and restart=no. No wrapper or init process may sit above the worker supervisor.
FROM production AS worker
HEALTHCHECK NONE
STOPSIGNAL SIGTERM
ENTRYPOINT ["/usr/local/bin/start-worker.sh"]
CMD []

# -----------------------------------------------------------------------------
# Stage 7: ci
# CI target. Dev image with full source and dev dependencies baked in.
# Avoids Docker-out-of-Docker volume mount issues in CI pipelines.
# -----------------------------------------------------------------------------
FROM dev AS ci

USER root

# Validate both nginx configurations with the real binary in integration tests, and
# give the CI package store's tests the zstd command that `tar --zstd` runs.
RUN apt-get update -qq \
    && DEBIAN_FRONTEND=noninteractive apt-get install -yqq --no-install-recommends nginx zstd \
    && rm -rf /var/lib/apt/lists/*

# Copy PHP config with higher memory limit for CI
COPY ./docker/dev/php.ini /usr/local/etc/php/conf.d/zzz-baander.ini

# Copy full source tree
COPY --chown=www-data:www-data . ${APP_HOME}/
COPY --chown=www-data:www-data .env.example ${APP_HOME}/.env

# Generate stub OAuth keys so PHPStan's Symfony extension can boot the kernel
RUN umask 077 && mkdir -p ${APP_HOME}/config/secrets/oauth \
    && openssl genrsa -out ${APP_HOME}/config/secrets/oauth/private.key 2048 2>/dev/null \
    && openssl rsa -in ${APP_HOME}/config/secrets/oauth/private.key -pubout -out ${APP_HOME}/config/secrets/oauth/public.key 2>/dev/null \
    && chown -R www-data:www-data ${APP_HOME}/config/secrets

# PHPUnit's isolated child processes read PHP ini rather than the parent's -d flags.
# Give them the same memory ceiling as the sharded runner, and leave writable
# cache and coverage directories for the unprivileged test process.
RUN COMPOSER_MEMORY_LIMIT=-1 composer install --optimize-autoloader --no-interaction --no-progress \
    && composer tooling:autoload \
    && mkdir -p ${APP_HOME}/coverage \
    && printf 'memory_limit=512M\n' > /usr/local/etc/php/conf.d/zzzz-baander-ci-memory.ini \
    && chown -R www-data:www-data ${APP_HOME}/var ${APP_HOME}/coverage

USER www-data

FROM node-dist AS dev-tools
RUN corepack enable && corepack prepare yarn@4.18.1 --activate && mkdir -p /work
WORKDIR /work

FROM dev AS dev-runtime
USER root
RUN printf 'memory_limit=512M\n' > /usr/local/etc/php/conf.d/zz-baander-development-memory.ini
COPY --chown=www-data:www-data . ${APP_HOME}/
COPY --chown=www-data:www-data .env.example ${APP_HOME}/.env
USER www-data
RUN COMPOSER_MEMORY_LIMIT=-1 composer install --optimize-autoloader --no-interaction --no-progress --no-scripts && \
    composer dump-autoload --optimize --no-interaction && \
    composer tooling:autoload

FROM ${OPERATOR_HELPER_IMAGE} AS operator-helper

FROM production AS dev-operator
USER root
ARG OPERATOR_HELPER_IMAGE
RUN test "$OPERATOR_HELPER_IMAGE" != scratch || { echo 'Use php scripts/build-application.php --target dev-operator --tag baander-operator:local' >&2; exit 1; }
COPY --from=operator-helper /usr/local/bin/docker /usr/local/bin/docker
HEALTHCHECK NONE
ENTRYPOINT ["/usr/local/bin/start-operator.sh"]
CMD []
