# Base stage with common dependencies
FROM php:8-zts AS base

# Copy FFmpeg binaries from static build
COPY --from=martinjuul/ffmpeg-baander-static /usr/src/ffmpeg-build-script/workspace/bin/ffmpeg /bin/ffmpeg
COPY --from=martinjuul/ffmpeg-baander-static /usr/src/ffmpeg-build-script/workspace/bin/ffprobe /bin/ffprobe
COPY --from=martinjuul/ffmpeg-baander-static /usr/src/ffmpeg-build-script/workspace/bin/ffplay /bin/ffplay

# Build arguments and environment variables
ARG BUILD_ARGUMENT_ENV=dev
ARG HOST_UID=1000
ARG HOST_GID=1000
ARG INSIDE_DOCKER_CONTAINER=1
ARG ESSENTIA_VERSION=2.1_beta5

ARG XDEBUG_CONFIG=main
ARG XDEBUG_VERSION=3.4.4

ENV XDEBUG_CONFIG=$XDEBUG_CONFIG \
    XDEBUG_VERSION=$XDEBUG_VERSION

ENV ENV=$BUILD_ARGUMENT_ENV \
    APP_HOME=/var/www/html \
    USERNAME=www-data \
    INSIDE_DOCKER_CONTAINER=$INSIDE_DOCKER_CONTAINER \
    COMPOSER_ALLOW_SUPERUSER=1

# Install system dependencies
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
        cron \
        curl \
        wget \
        nano \
        nodejs \
        procps \
        sudo \
        supervisor \
        unzip \
        iputils-ping \
        libavif-dev \
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
    npm i -g yarn && \
    rm -rf /var/lib/apt/lists/* && \
    apt-get clean

# Download & verify
RUN pip install --break-system-packages essentia

# Configure PHP extensions
RUN set -xe && \
    docker-php-ext-configure gd --with-webp --with-jpeg --with-xpm --with-freetype && \
    docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql && \
    docker-php-ext-configure intl

# Install PHP extensions
RUN set -xe && \
    docker-php-ext-install -j "$(nproc)" \
      exif \
      ffi \
      gd \
      gettext \
      intl \
      opcache \
      pcntl \
      pdo \
      pdo_pgsql \
      pgsql \
      sockets \
      zip \
      shmop \
      sysvmsg

RUN set -xe \
    && pecl channel-update pecl.php.net \
    && mkdir -p /usr/local/src/pecl \
    # protobuf
    && pecl bundle -d /usr/local/src/pecl protobuf \
    && docker-php-ext-configure /usr/local/src/pecl/protobuf \
    && docker-php-ext-install -j$(nproc) /usr/local/src/pecl/protobuf \
    # grpc
    && pecl bundle -d /usr/local/src/pecl grpc \
    && docker-php-ext-configure /usr/local/src/pecl/grpc \
    && docker-php-ext-install -j$(nproc) /usr/local/src/pecl/grpc \
    # jsonpath
    && pecl bundle -d /usr/local/src/pecl jsonpath \
    && docker-php-ext-configure /usr/local/src/pecl/jsonpath \
    && docker-php-ext-install -j$(nproc) /usr/local/src/pecl/jsonpath \
    # ssh2
    && pecl bundle -d /usr/local/src/pecl ssh2 \
    && docker-php-ext-configure /usr/local/src/pecl/ssh2 \
    && docker-php-ext-install -j$(nproc) /usr/local/src/pecl/ssh2 \
    # imagick
    && pecl bundle -d /usr/local/src/pecl imagick \
    && docker-php-ext-configure /usr/local/src/pecl/imagick \
    && docker-php-ext-install -j$(nproc) /usr/local/src/pecl/imagick \
    # igbinary
    && pecl bundle -d /usr/local/src/pecl igbinary \
    && docker-php-ext-configure /usr/local/src/pecl/igbinary \
    && docker-php-ext-install -j$(nproc) /usr/local/src/pecl/igbinary \
    # redis
    && pecl bundle -d /usr/local/src/pecl redis \
    && docker-php-ext-configure /usr/local/src/pecl/redis --enable-redis-igbinary --enable-redis-lzf --enable-redis-zstd --with-liblz4 \
    && docker-php-ext-install -j$(nproc) /usr/local/src/pecl/redis \
    # mailparse
    && pecl bundle -d /usr/local/src/pecl mailparse \
    && docker-php-ext-configure /usr/local/src/pecl/mailparse \
    && docker-php-ext-install -j$(nproc) /usr/local/src/pecl/mailparse \
    # inotify
    && pecl bundle -d /usr/local/src/pecl inotify \
    && docker-php-ext-configure /usr/local/src/pecl/inotify \
    && docker-php-ext-install -j$(nproc) /usr/local/src/pecl/inotify \
    # excimer
    && pecl bundle -d /usr/local/src/pecl excimer \
    && docker-php-ext-configure /usr/local/src/pecl/excimer \
    && docker-php-ext-install -j$(nproc) /usr/local/src/pecl/excimer \
    # yaml
    && pecl bundle -d /usr/local/src/pecl yaml \
    && docker-php-ext-configure /usr/local/src/pecl/yaml \
    && docker-php-ext-install -j$(nproc) /usr/local/src/pecl/yaml \
    # parallel
#    && pecl bundle -d /usr/local/src/pecl parallel \
#    && docker-php-ext-configure /usr/local/src/pecl/parallel \
#    && docker-php-ext-install -j$(nproc) /usr/local/src/pecl/parallel \
    # uv
    && pecl bundle -d /usr/local/src/pecl uv \
    && docker-php-ext-configure /usr/local/src/pecl/uv \
    && docker-php-ext-install -j$(nproc) /usr/local/src/pecl/uv \
    # swoole
    && pecl bundle -d /usr/local/src/pecl swoole \
    && docker-php-ext-configure /usr/local/src/pecl/swoole --enable-swoole-thread --enable-sockets --enable-swoole-curl --enable-cares --enable-swoole-pgsql \
    && docker-php-ext-install -j$(nproc) /usr/local/src/pecl/swoole \
    # opentelemetry
    && pecl bundle -d /usr/local/src/pecl opentelemetry \
    && docker-php-ext-configure /usr/local/src/pecl/opentelemetry \
    && docker-php-ext-install -j$(nproc) /usr/local/src/pecl/opentelemetry \
    && rm -rf /usr/local/src/pecl \
    && rm -rf /tmp/* \
    && rm -rf /var/list/apt/* \
    && rm -rf /var/lib/apt/lists/* \
    && apt-get clean

# Setup user permissions
RUN set -xe && \
    mkdir -p ${APP_HOME}/public && \
    mkdir -p /home/${USERNAME} && \
    chown ${USERNAME}:${USERNAME} /home/${USERNAME} && \
    usermod -o -u ${HOST_UID} ${USERNAME} -d /home/${USERNAME} && \
    groupmod -o -g ${HOST_GID} ${USERNAME} && \
    chown -R ${USERNAME}:${USERNAME} ${APP_HOME}

# Copy certificates and update CA store
COPY ./docker/dev/ca.crt /usr/local/share/ca-certificates/ca-self.crt
COPY ./docker/dev/juul.localdomain.crt /usr/local/share/ca-certificates/juul.localdomain.crt
RUN update-ca-certificates

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN chmod +x /usr/bin/composer

# Setup supervisor and cron
RUN mkdir -p /var/log/supervisor
COPY --chown=root:root ./docker/general/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY --chown=root:crontab ./docker/general/cron /var/spool/cron/crontabs/root
RUN chmod 0600 /var/spool/cron/crontabs/root

# Set working directory
WORKDIR ${APP_HOME}

# Copy and configure Xdebug
COPY /docker/dev/xdebug-main.ini /docker/dev/xdebug.ini
RUN mv /docker/dev/xdebug.ini /usr/local/etc/php/conf.d/

RUN set -xe && \
    pecl install xdebug-${XDEBUG_VERSION} && \
    docker-php-ext-enable xdebug

# Switch to app user
USER ${USERNAME}

# Add bash aliases
RUN echo 'alias artisan="php /var/www/html/artisan"' >> /home/${USERNAME}/.bashrc

# Copy application files
COPY --chown=${USERNAME}:${USERNAME} . ${APP_HOME}/
COPY --chown=${USERNAME}:${USERNAME} .env ${APP_HOME}/.env
COPY --chown=${USERNAME}:${USERNAME} start-swoole-server ${APP_HOME}/start-swoole-server
RUN chmod +x ./start-swoole-server

# Install Composer dependencies
RUN COMPOSER_MEMORY_LIMIT=-1 composer install --optimize-autoloader --no-interaction --no-progress

CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
