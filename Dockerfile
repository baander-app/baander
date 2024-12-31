FROM php:zts-bookworm

# set main params
ARG BUILD_ARGUMENT_ENV=dev
ENV ENV=$BUILD_ARGUMENT_ENV
ENV APP_HOME /var/www/html
ARG HOST_UID=1000
ARG HOST_GID=1000
ENV USERNAME=www-data
ARG INSIDE_DOCKER_CONTAINER=1
ENV INSIDE_DOCKER_CONTAINER=$INSIDE_DOCKER_CONTAINER
ARG XDEBUG_CONFIG=main
ENV XDEBUG_CONFIG=$XDEBUG_CONFIG
ARG XDEBUG_VERSION=3.3.2
ENV XDEBUG_VERSION=$XDEBUG_VERSION

# Check environment
RUN set -xe && \
    if [ "${BUILD_ARGUMENT_ENV}" = "default" ]; then echo "Set BUILD_ARGUMENT_ENV in docker build-args like --build-arg BUILD_ARGUMENT_ENV=dev" && exit 2; \
    elif [ "${BUILD_ARGUMENT_ENV}" = "dev" ]; then echo "Building development environment."; \
    else echo "Set correct BUILD_ARGUMENT_ENV in docker build-args like --build-arg BUILD_ARGUMENT_ENV=dev. Available choices are dev" && exit 2; \
    fi

# Install all the dependencies and enable PHP modules
RUN set -xe \
    && curl -sL https://deb.nodesource.com/setup_20.x  | bash - \
    && DEBIAN_FRONTEND=noninteractive apt-get update -qq \
    && DEBIAN_FRONTEND=noninteractive apt-get upgrade -yqq \
    && DEBIAN_FRONTEND=noninteractive apt-get install -yqq -o=Dpkg::Use-Pty=0 \
      cron \
      curl \
      ffmpeg \
      git \
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
      liblzf-dev \
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
      libyaml-dev \
      zlib1g-dev

RUN set -xe \
    && docker-php-ext-configure gd --with-webp --with-jpeg --with-xpm --with-freetype \
    && docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
    && docker-php-ext-configure intl \
    && npm i -g yarn

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
      zip

RUN set -xe \
    && pecl channel-update pecl.php.net \
    && mkdir -p /usr/local/src/pecl \
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
    # swoole
    && pecl bundle -d /usr/local/src/pecl swoole \
    && docker-php-ext-configure /usr/local/src/pecl/swoole --enable-sockets --enable-swoole-curl --enable-cares --enable-swoole-pgsql \
    && docker-php-ext-install -j$(nproc) /usr/local/src/pecl/swoole \
    && rm -rf /usr/local/src/pecl \
    && rm -rf /tmp/* \
    && rm -rf /var/list/apt/* \
    && rm -rf /var/lib/apt/lists/* \
    && apt-get clean

# create document root, fix permissions for www-data user and change owner to www-data
RUN set -xe \
    && mkdir -p ${APP_HOME}/public \
    && mkdir -p /home/${USERNAME} && chown ${USERNAME}:${USERNAME} /home/${USERNAME} \
    && usermod -o -u ${HOST_UID} ${USERNAME} -d /home/${USERNAME} \
    && groupmod -o -g ${HOST_GID} ${USERNAME} \
    && chown -R ${USERNAME}:${USERNAME} ${APP_HOME}

# put php config for Laravel
COPY ./docker/$BUILD_ARGUMENT_ENV/php.ini /usr/local/etc/php/php.ini

# install Xdebug in case dev/test environment
COPY ./docker/general/do_we_need_xdebug.sh /tmp/
COPY ./docker/dev/xdebug-${XDEBUG_CONFIG}.ini /tmp/xdebug.ini
RUN chmod u+x /tmp/do_we_need_xdebug.sh && /tmp/do_we_need_xdebug.sh

# install composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN chmod +x /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER 1

# add supervisor
RUN mkdir -p /var/log/supervisor
COPY --chown=root:root ./docker/general/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
# add crontab
COPY --chown=root:crontab ./docker/general/cron /var/spool/cron/crontabs/root
RUN chmod 0600 /var/spool/cron/crontabs/root

# set working directory
WORKDIR ${APP_HOME}
USER ${USERNAME}

# Add necessary stuff to bash autocomplete
RUN set -xe \
    && echo 'alias artisan="php /var/www/html/artisan"' >> /home/${USERNAME}/.bashrc

# copy source files and config file
COPY --chown=${USERNAME}:${USERNAME} . ${APP_HOME}/
COPY --chown=${USERNAME}:${USERNAME} .env ${APP_HOME}/.env
COPY --chown=${USERNAME}:${USERNAME} start-swoole-server ${APP_HOME}/start-swoole-server

RUN set -xe \
    && chmod +x ./start-swoole-server

# install all PHP dependencies
# TODO fix
#RUN if [ "${BUILD_ARGUMENT_ENV}" = "dev" ] || [ "${BUILD_ARGUMENT_ENV}" = "test" ]; then COMPOSER_MEMORY_LIMIT=-1 composer install --optimize-autoloader --no-interaction --no-progress; \
#    else COMPOSER_MEMORY_LIMIT=-1 composer install --optimize-autoloader --no-interaction --no-progress --no-dev; \
#    fi
RUN set -xe \
    && COMPOSER_MEMORY_LIMIT=-1 composer install

CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
