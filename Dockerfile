# =============================================================================
# Multi-stage Dockerfile for Baander
# Stages: base -> {builder-php, builder-swoole, builder-tsduck} -> builder -> runtime -> {dev, production}
#
# Heavy build steps (Swoole, TSDuck) run in parallel via BuildKit.
# Requires BuildKit for cache mounts and parallel stages: DOCKER_BUILDKIT=1
# =============================================================================

# -----------------------------------------------------------------------------
# Stage 1: base
# System dependencies, Node.js, and the pie tool.
# Provides the common foundation for both builder and runtime.
# FFmpeg is installed from Debian apt (build with: make build-ffmpeg for static).
# -----------------------------------------------------------------------------
FROM php:8.5.2-zts-bookworm AS base

ARG LIBURING_VERSION=2.13
ARG TARGETPLATFORM

# Install system dependencies.
# --mount=type=cache caches APT indices and packages across rebuilds.
RUN set -xe && \
    curl -sL https://deb.nodesource.com/setup_24.x | bash - && \
    DEBIAN_FRONTEND=noninteractive apt-get update -qq && \
    DEBIAN_FRONTEND=noninteractive apt-get upgrade -yqq && \
    DEBIAN_FRONTEND=noninteractive apt-get install -yqq -o=Dpkg::Use-Pty=0 \
        build-essential \
        git \
        python3 \
        python3-pip \
        libeigen3-dev \
        libyaml-dev \
        libfftw3-dev \
        libavcodec-dev \
        libavformat-dev \
        libavutil-dev \
        libswresample-dev \
        libsamplerate0-dev \
        libtag1-dev \
        libchromaprint-dev \
        ca-certificates \
        bison \
        cron \
        curl \
        wget \
        nano \
        nodejs \
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

COPY --from=ghcr.io/php/pie:bin /pie /bin/pie

# Install FFmpeg from Debian apt
RUN set -xe && \
    DEBIAN_FRONTEND=noninteractive apt-get update -qq && \
    DEBIAN_FRONTEND=noninteractive apt-get install -yqq -o=Dpkg::Use-Pty=0 \
        ffmpeg && \
    rm -rf /var/lib/apt/lists/*

# -----------------------------------------------------------------------------
# Stage 2a: builder-php
# PHP core extensions, liburing, PECL extensions, essentia, and Composer.
# Runs in parallel with builder-swoole and builder-tsduck.
# -----------------------------------------------------------------------------
FROM base AS builder-php

ARG LIBURING_VERSION=2.13

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
RUN set -xe && \
    wget https://github.com/axboe/liburing/archive/refs/tags/liburing-${LIBURING_VERSION}.tar.gz && \
    tar zxf liburing-${LIBURING_VERSION}.tar.gz && \
    cd liburing-liburing-${LIBURING_VERSION} && \
    ./configure && \
    make -j$(nproc) install && \
    cd .. \
    rm -rf liburing-${LIBURING_VERSION} && rm -f liburing-${LIBURING_VERSION}.tar.gz

# Install PECL extensions.
# --mount=type=cache,target=/tmp/pecl persists downloaded source across rebuilds.
RUN --mount=type=cache,target=/tmp/pecl \
    set -xe \
    && pecl channel-update pecl.php.net \
    && mkdir -p /tmp/pecl \
    # imagick
    && pecl bundle -d /tmp/pecl imagick \
    && docker-php-ext-configure /tmp/pecl/imagick \
    && docker-php-ext-install -j$(nproc) /tmp/pecl/imagick \
    # igbinary
    && pecl bundle -d /tmp/pecl igbinary \
    && docker-php-ext-configure /tmp/pecl/igbinary \
    && docker-php-ext-install -j$(nproc) /tmp/pecl/igbinary \
    # redis
    && pecl bundle -d /tmp/pecl redis \
    && docker-php-ext-configure /tmp/pecl/redis --enable-redis-igbinary --enable-redis-lzf --enable-redis-zstd --with-liblz4 \
    && docker-php-ext-install -j$(nproc) /tmp/pecl/redis \
    # mailparse
    && pecl bundle -d /tmp/pecl mailparse \
    && docker-php-ext-configure /tmp/pecl/mailparse \
    && docker-php-ext-install -j$(nproc) /tmp/pecl/mailparse \
    # inotify
    && pecl bundle -d /tmp/pecl inotify \
    && docker-php-ext-configure /tmp/pecl/inotify \
    && docker-php-ext-install -j$(nproc) /tmp/pecl/inotify \
    # excimer
    && pecl bundle -d /tmp/pecl excimer \
    && docker-php-ext-configure /tmp/pecl/excimer \
    && docker-php-ext-install -j$(nproc) /tmp/pecl/excimer \
    # yaml
    && pecl bundle -d /tmp/pecl yaml \
    && docker-php-ext-configure /tmp/pecl/yaml \
    && docker-php-ext-install -j$(nproc) /tmp/pecl/yaml \
    # uv
    && pecl bundle -d /tmp/pecl uv \
    && docker-php-ext-configure /tmp/pecl/uv \
    && docker-php-ext-install -j$(nproc) /tmp/pecl/uv

# Install essentia via pip.
# --mount=type=cache reuses downloaded wheels and build artifacts.
RUN --mount=type=cache,target=/root/.cache/pip \
    pip install --break-system-packages essentia

# Install Composer from official image
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN chmod +x /usr/bin/composer

# -----------------------------------------------------------------------------
# Stage 2b: builder-swoole
# Swoole extension with io_uring support.
# Builds liburing first (small, ~30s) then Swoole.
# Runs in parallel with builder-php and builder-tsduck.
# -----------------------------------------------------------------------------
FROM base AS builder-swoole

ARG LIBURING_VERSION=2.13

# Compile liburing (duplicated — avoids cross-stage dependency, ~30s build)
RUN set -xe && \
    wget https://github.com/axboe/liburing/archive/refs/tags/liburing-${LIBURING_VERSION}.tar.gz && \
    tar zxf liburing-${LIBURING_VERSION}.tar.gz && \
    cd liburing-liburing-${LIBURING_VERSION} && \
    ./configure && \
    make -j$(nproc) install && \
    cd .. \
    rm -rf liburing-${LIBURING_VERSION} && rm -f liburing-${LIBURING_VERSION}.tar.gz

# Install sockets extension (required by Swoole's --enable-sockets)
RUN set -xe && \
    docker-php-ext-install -j "$(nproc)" sockets

# Install Swoole from source with custom flags.
# --mount=type=cache persists pie's download and build cache.
RUN --mount=type=cache,target=/root/.cache/pie \
    set -xe && \
    pie install swoole/swoole:dev-master \
        --enable-swoole-thread \
        --enable-sockets \
        --enable-swoole-curl \
        --enable-cares \
        --enable-swoole-pgsql \
        --enable-swoole-sqlite \
        --enable-iouring \
        --enable-zstd && \
    # Ensure extension= directive exists — pie generates it but keep as safety net
    grep -q 'extension=swoole' /usr/local/etc/php/conf.d/docker-php-ext-swoole.ini || \
        echo 'extension=swoole.so' > /usr/local/etc/php/conf.d/docker-php-ext-swoole.ini

# -----------------------------------------------------------------------------
# Stage 2c: builder-tsduck
# TSDuck MPEG-TS toolkit (C++ library + CLI tools for PHP FFI bindings).
# Runs in parallel with builder-php and builder-swoole.
# -----------------------------------------------------------------------------
FROM base AS builder-tsduck

ARG TSDUCK_VERSION=v3.43-4549

# Build TSDuck from source (shared library + CLI tools for PHP FFI bindings)
RUN set -xe && \
    git clone --depth 1 --branch ${TSDUCK_VERSION} https://github.com/tsduck/tsduck.git /tmp/tsduck-src && \
    cd /tmp/tsduck-src && \
    make -j$(nproc) NOSTATIC=true NOJAVA=true NOPYTHON=true NOPHP=true CXXFLAGS_EXTRA="-Wno-restrict -Wno-deprecated-copy" && \
    make -j$(nproc) NOTEST=true -C src install-tools && \
    ldconfig

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

# -----------------------------------------------------------------------------
# Stage 4: runtime
# Copies only compiled PHP extension artifacts and runtime library packages.
# No build tools, no -dev packages. This is the minimal runtime foundation.
# -----------------------------------------------------------------------------
FROM base AS runtime

# Runtime ARGs
ARG HOST_UID=1000
ARG HOST_GID=1000
ARG INSIDE_DOCKER_CONTAINER=1

ENV APP_HOME=/var/www/html \
    USERNAME=www-data \
    INSIDE_DOCKER_CONTAINER=$INSIDE_DOCKER_CONTAINER \
    COMPOSER_ALLOW_SUPERUSER=1

# Install ONLY runtime library packages (no -dev packages).
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
        libssl3 \
        libmagickwand-6.q16-6 \
        libjpeg62-turbo \
        libpng16-16 \
        libwebp7 \
        libxpm4 \
        libfreetype6 \
        libzip4 \
        libbrotli1 \
        liblz4-1 \
        libzstd1 \
        zlib1g \
        libcurl4 \
        libxml2 \
        libicu72 \
        libsqlite3-0 \
        libyaml-0-2 \
        libuv1 \
        libc-ares2 \
        libssh2-1 \
        # Essentia runtime dependencies
        libfftw3-double3 \
        libsamplerate0 \
        libtag1v5 \
        libchromaprint1 \
        # libavif runtime
        libavif15 \
        # TSDuck runtime dependencies
        libedit2 \
        libpcsclite1 \
        librist4 \
        libsrt1.5-openssl \
        # Intel QSV / VAAPI hardware transcoding
        libva2 \
        libdrm2 \
        vainfo \
        && rm -rf /var/lib/apt/lists/* && \
    apt-get clean

# Copy compiled PHP extensions (entire directory tree)
COPY --from=builder /usr/local/lib/php/ /usr/local/lib/php/

# Copy extension .ini configuration files
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# Create php.ini so the host mount target exists in the container.
# The actual config is provided via docker-compose volume mount.
RUN cp /usr/local/etc/php/php.ini-development /usr/local/etc/php/php.ini

# Copy compiled liburing shared libraries
COPY --from=builder /usr/local/lib/liburing* /usr/local/lib/
COPY --from=builder /usr/lib/liburing* /usr/lib/

# Copy TSDuck shared libraries and CLI tools
COPY --from=builder /usr/lib/libtsduck* /usr/lib/
COPY --from=builder /usr/lib/libtscore* /usr/lib/
COPY --from=builder /usr/bin/ts* /usr/bin/

# Update linker cache for copied libraries
RUN ldconfig

# Set up user permissions
RUN set -xe && \
    mkdir -p ${APP_HOME}/public && \
    mkdir -p /home/${USERNAME} && \
    chown ${USERNAME}:${USERNAME} /home/${USERNAME} && \
    usermod -o -u ${HOST_UID} ${USERNAME} -d /home/${USERNAME} && \
    groupmod -o -g ${HOST_GID} ${USERNAME} && \
    chown -R ${USERNAME}:${USERNAME} ${APP_HOME}

# Copy cron file
COPY --chown=root:crontab ./docker/general/cron /var/spool/cron/crontabs/root
RUN chmod 0600 /var/spool/cron/crontabs/root

# Set working directory
WORKDIR ${APP_HOME}

# Switch to app user
USER ${USERNAME}

CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

# -----------------------------------------------------------------------------
# Stage 5: dev
# Development target. Adds Xdebug, CA certificates, and debug aliases.
# Designed for use with docker-compose volume mount workflow.
# -----------------------------------------------------------------------------
FROM runtime AS dev

ARG HOST_UID=1000
ARG HOST_GID=1000
ARG XDEBUG_CONFIG=main
ARG XDEBUG_VERSION=3.5.0

ENV XDEBUG_CONFIG=$XDEBUG_CONFIG \
    XDEBUG_VERSION=$XDEBUG_VERSION

# Switch to root for setup
USER root

# Copy Composer from builder
COPY --from=builder /usr/bin/composer /usr/bin/composer

# Install Xdebug.
# --mount=type=cache persists PECL download/build across rebuilds.
RUN --mount=type=cache,target=/tmp/pecl \
    set -xe && \
    pecl bundle -d /tmp/pecl xdebug \
    && docker-php-ext-configure /tmp/pecl/xdebug \
    && docker-php-ext-install -j$(nproc) /tmp/pecl/xdebug

# Copy CA certificates for local development
COPY ./docker/dev/ca.crt /usr/local/share/ca-certificates/ca-self.crt
COPY ./docker/dev/juul.localdomain.crt /usr/local/share/ca-certificates/juul.localdomain.crt
RUN update-ca-certificates

# Copy runtime entrypoint (disables Xdebug before supervisord starts)
COPY ./docker/dev/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Copy Xdebug config directly to the correct location (one step)
COPY ./docker/dev/xdebug-main.ini /usr/local/etc/php/conf.d/xdebug.ini

# Copy Swoole config (runtime settings only — extension= is in docker-php-ext-swoole.ini from pie)
COPY ./docker/dev/swoole-runtime.ini /usr/local/etc/php/conf.d/swoole-runtime.ini

# Set up HOST_UID/HOST_GID remapping
RUN set -xe && \
    usermod -o -u ${HOST_UID} www-data && \
    groupmod -o -g ${HOST_GID} www-data

# Set up bash aliases
RUN echo 'alias sf="php /var/www/html/bin/console"' >> /home/www-data/.bashrc && \
    echo 'alias sf-dev="php /var/www/html/bin/console swoole:server:run"' >> /home/www-data/.bashrc && \
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
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

# -----------------------------------------------------------------------------
# Stage 6: production
# Production target. Copies source and installs Composer dependencies.
# Designed for standalone use (CI deploys, image registry pushes).
# When used with docker-compose.yml volume mount, host files overlay this.
# -----------------------------------------------------------------------------
FROM runtime AS production

# Switch to root for COPY and composer install
USER root

# Copy Composer from builder (already installed there)
COPY --from=builder /usr/bin/composer /usr/bin/composer

# Copy Composer files and patches first for layer caching.
# The patches directory is needed by cweagans/composer-patches during install.
COPY composer.json composer.lock ./
COPY patches/ ./patches/

# Install production dependencies only.
# --mount=type=cache persists Composer's download and repository caches.
RUN COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Copy the full application source tree
COPY --chown=www-data:www-data . ${APP_HOME}/

# Clear the production cache
RUN php bin/console cache:clear

# Switch to app user
USER www-data

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD ["php", "bin/console", "app:health:check"]

CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

# -----------------------------------------------------------------------------
# Stage 7: ci
# CI target. Dev image with full source and dev dependencies baked in.
# Avoids Docker-out-of-Docker volume mount issues in CI pipelines.
# -----------------------------------------------------------------------------
FROM dev AS ci

USER root

# Copy PHP config with higher memory limit for CI
COPY ./docker/dev/php.ini /usr/local/etc/php/conf.d/zzz-baander.ini

# Copy full source tree
COPY --chown=www-data:www-data . ${APP_HOME}/

# Generate stub OAuth keys so PHPStan's Symfony extension can boot the kernel
RUN mkdir -p ${APP_HOME}/config/secrets/oauth \
    && openssl genrsa -out ${APP_HOME}/config/secrets/oauth/private.key 2048 2>/dev/null \
    && openssl rsa -in ${APP_HOME}/config/secrets/oauth/private.key -pubout -out ${APP_HOME}/config/secrets/oauth/public.key 2>/dev/null \
    && chown -R www-data:www-data ${APP_HOME}/config/secrets

# Install dev dependencies
RUN COMPOSER_MEMORY_LIMIT=-1 composer install --optimize-autoloader --no-interaction --no-progress --ignore-platform-req=ext-swoole

USER www-data
