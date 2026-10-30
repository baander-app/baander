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
ARG OPERATOR_HELPER_IMAGE=scratch
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
        libyaml-dev \
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
# PHP core extensions, liburing, PECL extensions, and Composer.
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
COPY scripts/retain-build-evidence.php /usr/local/bin/retain-build-evidence.php
RUN set -xe && \
    wget https://github.com/axboe/liburing/archive/refs/tags/liburing-${LIBURING_VERSION}.tar.gz && \
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
    && docker-php-ext-install -j$(nproc) /tmp/pecl/uv \
    && for extension in imagick igbinary redis mailparse inotify excimer yaml uv; do \
        php /usr/local/bin/retain-build-evidence.php collect \
            --output /tmp/build-evidence-php --id "pecl-${extension}" --name "${extension}" \
            --source-dir "/tmp/pecl/${extension}" --package-xml "/tmp/pecl/${extension}/package.xml" \
            --source-url "https://pecl.php.net/package/${extension}" \
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
    cd .. && \
    rm -rf "liburing-liburing-${LIBURING_VERSION}" && rm -f "liburing-${LIBURING_VERSION}.tar.gz"

# Install sockets extension (required by Swoole's --enable-sockets)
RUN set -xe && \
    docker-php-ext-install -j "$(nproc)" sockets

# Match the exact native API required by Composer and the Swoole bundle stubs.
# Install Swoole from source with custom flags.
# Keep the async thread file backend: io_uring requires syscalls denied by the
# deployment container's default seccomp profile and has no runtime fallback.
# --mount=type=cache persists pie's download and build cache.
COPY scripts/retain-build-evidence.php /usr/local/bin/retain-build-evidence.php
RUN --mount=type=cache,target=/root/.cache/pie \
    set -xe && \
    export PIE_WORKING_DIRECTORY=/tmp/pie-evidence && \
    pie download swoole/swoole:6.2.0 && \
    for source in /tmp/pie-evidence/php*/vendor/swoole/swoole; do \
        php /usr/local/bin/retain-build-evidence.php collect \
            --output /tmp/build-evidence-swoole --id swoole --name Swoole \
            --source-dir "$source" --package-xml "$source/package.xml" \
            --source-url https://github.com/swoole/swoole-src \
            --artifact '/usr/local/lib/php/extensions/*/swoole.so'; \
    done && \
    pie install swoole/swoole:6.2.0 \
        --enable-swoole-thread \
        --enable-sockets \
        --enable-swoole-curl \
        --enable-cares \
        --enable-swoole-pgsql \
        --enable-swoole-sqlite \
        --enable-zstd && \
    # Ensure extension= directive exists — pie generates it but keep as safety net
    { grep -q 'extension=swoole' /usr/local/etc/php/conf.d/docker-php-ext-swoole.ini || \
        echo 'extension=swoole.so' > /usr/local/etc/php/conf.d/docker-php-ext-swoole.ini; }

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
    php /usr/local/bin/retain-build-evidence.php phar \
        --output /tmp/build-evidence-tools --id pie --name PIE \
        --archive /bin/pie --artifact /bin/pie && \
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

ENV XDEBUG_CONFIG=$XDEBUG_CONFIG \
    XDEBUG_VERSION=$XDEBUG_VERSION

# Switch to root for setup
USER root

# Copy Composer from builder
COPY --from=builder /usr/bin/composer /usr/bin/composer

# Install the requested archive directly, bypassing PECL REST metadata lookup.
# --mount=type=cache persists PECL download/build across rebuilds.
RUN --mount=type=cache,target=/tmp/pecl \
    set -xe && \
    pecl bundle -d /tmp/pecl "https://pecl.php.net/get/xdebug-${XDEBUG_VERSION}.tgz" \
    && docker-php-ext-configure /tmp/pecl/xdebug \
    && docker-php-ext-install -j$(nproc) /tmp/pecl/xdebug

# Copy CA certificates for local development
COPY ./docker/dev/ca.crt /usr/local/share/ca-certificates/ca-self.crt
COPY ./docker/dev/juul.localdomain.crt /usr/local/share/ca-certificates/juul.localdomain.crt
RUN update-ca-certificates

# Copy runtime entrypoint (disables Xdebug before supervisord starts)
COPY --chmod=755 ./docker/dev/entrypoint.sh /usr/local/bin/entrypoint.sh

# Copy Xdebug config directly to the correct location (one step)
COPY ./docker/dev/xdebug-main.ini /usr/local/etc/php/conf.d/xdebug.ini

# Copy Swoole config (runtime settings only — extension= is in docker-php-ext-swoole.ini from pie)
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
    rm -rf /home/www-data/.cache/composer vendor/nelmio/api-doc-bundle/public

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

# Validate both nginx configurations with the real binary in integration tests.
RUN apt-get update -qq \
    && DEBIAN_FRONTEND=noninteractive apt-get install -yqq --no-install-recommends nginx \
    && rm -rf /var/lib/apt/lists/*

# Copy PHP config with higher memory limit for CI
COPY ./docker/dev/php.ini /usr/local/etc/php/conf.d/zzz-baander.ini

# Copy full source tree
COPY --chown=www-data:www-data . ${APP_HOME}/

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

FROM node:22-bookworm-slim AS dev-tools
RUN corepack enable && corepack prepare yarn@4.14.1 --activate && mkdir -p /work
WORKDIR /work

FROM dev AS dev-runtime
USER root
RUN printf 'memory_limit=512M\n' > /usr/local/etc/php/conf.d/zz-baander-development-memory.ini
COPY --chown=www-data:www-data . ${APP_HOME}/
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
