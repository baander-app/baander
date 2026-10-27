FROM debian:trixie-slim AS tooling-build

ARG PHP_VERSION=8.5.2
ARG PHP_SHA256=cb75a9b00a2806f7390dd64858ef42a47b443b3475769c8af6af33a18b1381f1
ARG SWOOLE_VERSION=6.2.0
ARG SWOOLE_SHA256=b66bf449440c1ba2727314e1e9c4c078978e7def21a02b729bbfec35601ae2ee
ARG YAML_VERSION=2.3.0
ARG YAML_SHA256=bc8404807a3a4dc896b310af21a7f8063aa238424ff77f27eb6ffa88b5874b8a
ARG TOOLING_BUILD_JOBS=4

RUN apt-get update && apt-get install -y --no-install-recommends \
        autoconf build-essential ca-certificates curl pkg-config xz-utils \
        libffi-dev libonig-dev libsqlite3-dev libssl-dev libxml2-dev libyaml-dev libzip-dev zlib1g-dev \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL "https://www.php.net/distributions/php-${PHP_VERSION}.tar.xz" -o /tmp/php.tar.xz \
    && echo "${PHP_SHA256}  /tmp/php.tar.xz" | sha256sum -c - \
    && mkdir /tmp/php \
    && tar -xJf /tmp/php.tar.xz -C /tmp/php --strip-components=1 \
    && cd /tmp/php \
    && ./configure --prefix=/opt/php \
        --with-config-file-path=/opt/php/etc --with-config-file-scan-dir=/opt/php/etc/conf.d \
        --disable-all --enable-cli --disable-cgi --disable-phpdbg \
        --enable-ctype --enable-dom --enable-fileinfo --enable-filter \
        --enable-mbstring --enable-pdo --enable-phar --enable-simplexml --enable-sockets \
        --enable-tokenizer --enable-xml --enable-xmlreader --enable-xmlwriter \
        --with-ffi --with-libxml --with-openssl --with-pdo-sqlite --with-sqlite3 --with-zip --with-zlib \
    && make -j"${TOOLING_BUILD_JOBS}" \
    && make install \
    && mkdir -p /opt/php/etc/conf.d \
    && rm -rf /tmp/php /tmp/php.tar.xz

RUN curl -fsSL "https://pecl.php.net/get/swoole-${SWOOLE_VERSION}.tgz" -o /tmp/swoole.tgz \
    && echo "${SWOOLE_SHA256}  /tmp/swoole.tgz" | sha256sum -c - \
    && mkdir /tmp/swoole \
    && tar -xzf /tmp/swoole.tgz -C /tmp/swoole --strip-components=1 \
    && cd /tmp/swoole \
    && /opt/php/bin/phpize \
    && ./configure --with-php-config=/opt/php/bin/php-config --enable-sockets --disable-brotli \
    && make -j"${TOOLING_BUILD_JOBS}" \
    && make install \
    && echo 'extension=swoole.so' > /opt/php/etc/conf.d/swoole.ini \
    && rm -rf /tmp/swoole /tmp/swoole.tgz

RUN curl -fsSL "https://pecl.php.net/get/yaml-${YAML_VERSION}.tgz" -o /tmp/yaml.tgz \
    && echo "${YAML_SHA256}  /tmp/yaml.tgz" | sha256sum -c - \
    && mkdir /tmp/yaml \
    && tar -xzf /tmp/yaml.tgz -C /tmp/yaml --strip-components=1 \
    && cd /tmp/yaml \
    && /opt/php/bin/phpize \
    && ./configure --with-php-config=/opt/php/bin/php-config --with-yaml \
    && make -j"${TOOLING_BUILD_JOBS}" \
    && make install \
    && echo 'extension=yaml.so' > /opt/php/etc/conf.d/yaml.ini \
    && rm -rf /tmp/yaml /tmp/yaml.tgz

FROM debian:trixie-slim AS tooling

RUN apt-get update && apt-get install -y --no-install-recommends \
        bash ca-certificates git make openssl libffi8 libonig5 libsqlite3-0 libssl3t64 libstdc++6 libxml2 \
        libyaml-0-2 libzip5 util-linux zlib1g \
    && rm -rf /var/lib/apt/lists/*

COPY --from=tooling-build /opt/php /opt/php
COPY --from=composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc /usr/bin/composer /usr/local/bin/composer
ENV PATH="/opt/php/bin:${PATH}"

RUN php -v && php --ri swoole && php --ri yaml \
    && php -r 'foreach (["dom", "ffi", "mbstring", "openssl", "pdo_sqlite", "sockets", "sqlite3", "tokenizer", "xml", "xmlwriter", "zip"] as $extension) { if (!extension_loaded($extension)) { throw new RuntimeException("Missing extension: " . $extension); } }'

FROM tooling AS runner

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        gnupg \
        build-essential \
        cmake \
        pkg-config \
        g++ \
        python3 \
        python3-pip \
        openjdk-21-jdk-headless \
        unzip \
        jq \
        tree \
        procps \
        zstd \
    && rm -rf /var/lib/apt/lists/*

# Official Docker CLI
RUN curl -fsSL https://download.docker.com/linux/debian/gpg \
        | gpg --dearmor -o /usr/share/keyrings/docker.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker.gpg] https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
        > /etc/apt/sources.list.d/docker.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends docker-ce-cli docker-buildx-plugin docker-compose-plugin \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/* \
    && corepack enable

# Emscripten SDK
RUN git clone https://github.com/emscripten-core/emsdk.git /opt/emsdk \
    && /opt/emsdk/emsdk install latest \
    && /opt/emsdk/emsdk activate latest \
    && echo '. /opt/emsdk/emsdk_env.sh' >> /etc/bash.bashrc

ENV PATH="/opt/emsdk:/opt/emsdk/upstream/bin:${PATH}"

# Verify toolchain
RUN cd /opt/emsdk && . ./emsdk_env.sh \
    && node --version \
    && javac --version \
    && docker --version \
    && docker buildx version \
    && docker compose version \
    && g++ --version \
    && cmake --version \
    && emcc --version
