FROM debian:trixie-slim@sha256:8aa5a7bf5a1955bddbe7d74fcdd0b91ab30e74362a8b73af672b0481b35b44a0 AS debian-dist

FROM node:24.22.2-trixie-slim@sha256:0820eaa85e07d0490446dd01d14707e17e6108a36988cf374899cd057eb3812d AS node-dist

FROM debian-dist AS tooling-build

ARG PHP_VERSION=8.5.13
ARG PHP_SHA256=607602acaf0c3061818e9916ec44a058e8b3d43959a83862b9cd3ec7bf5b785f
ARG SWOOLE_VERSION=6.2.4
ARG SWOOLE_SHA256=cb41348708cb3a360672cd4ffe84b9a6b4efee61773be7d76a11c39949b95334
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

RUN /opt/php/bin/php -r 'if (PHP_VERSION !== $argv[1] || PHP_ZTS || phpversion("swoole") !== $argv[2]) { exit(1); }' "$PHP_VERSION" "$SWOOLE_VERSION"

FROM debian-dist AS tooling

# The CI package store transfers archives with curl and packs them with `tar --zstd`.
RUN apt-get update && apt-get install -y --no-install-recommends \
        bash ca-certificates curl git make openssl libffi8 libonig5 libsqlite3-0 libssl3t64 libstdc++6 libxml2 \
        libyaml-0-2 libzip5 util-linux zlib1g zstd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=tooling-build /opt/php /opt/php
COPY --from=composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 /usr/bin/composer /usr/local/bin/composer
ENV PATH="/opt/php/bin:${PATH}"

RUN php -v && php --ri swoole && php --ri yaml \
    && php -r 'foreach (["dom", "ffi", "mbstring", "openssl", "pdo_sqlite", "sockets", "sqlite3", "tokenizer", "xml", "xmlwriter", "zip", "zlib"] as $extension) { if (!extension_loaded($extension)) { throw new RuntimeException("Missing extension: " . $extension); } } $db = new SQLite3(":memory:"); $db->exec("CREATE VIRTUAL TABLE logs USING fts5(message, tokenize=trigram)");'

FROM tooling AS runner

ARG DOCKER_APT_GPG_SHA256=1500c1f56fa9e26b9b8f42452a553675796ade0807cdce11975eb98170b3a570
ARG EMSDK_COMMIT=db04e88298d9916fc51fcd3743045ca3eb695127
ARG EMSCRIPTEN_VERSION=6.0.3

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

# Official Docker CLI. The repository key is pinned by SHA-256 (its primary key
# fingerprint is 9DC8 5822 9FC7 DD38 854A E2D8 8D81 803C 0EBF CD88); apt checks
# every Docker package against it.
RUN curl -fsSL https://download.docker.com/linux/debian/gpg -o /tmp/docker.gpg \
    && echo "${DOCKER_APT_GPG_SHA256}  /tmp/docker.gpg" | sha256sum -c - \
    && gpg --dearmor -o /usr/share/keyrings/docker.gpg /tmp/docker.gpg \
    && rm /tmp/docker.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker.gpg] https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
        > /etc/apt/sources.list.d/docker.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends docker-ce-cli docker-buildx-plugin docker-compose-plugin \
    && rm -rf /var/lib/apt/lists/*

# Node.js from the pinned node image, in the layout of Debian's nodejs package.
COPY --from=node-dist /usr/local/bin/node /usr/bin/node
COPY --from=node-dist /usr/local/lib/node_modules /usr/lib/node_modules
COPY --from=node-dist /usr/local/include/node /usr/include/node
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/bin/npx \
    && ln -s ../lib/node_modules/corepack/dist/corepack.js /usr/bin/corepack \
    && corepack enable

# Emscripten SDK at the commit and release the DSP qualification jobs pin.
RUN git init -q /opt/emsdk \
    && git -C /opt/emsdk fetch -q --depth 1 https://github.com/emscripten-core/emsdk.git "${EMSDK_COMMIT}" \
    && git -C /opt/emsdk checkout -q --detach FETCH_HEAD \
    && test "$(git -C /opt/emsdk rev-parse HEAD)" = "${EMSDK_COMMIT}" \
    && /opt/emsdk/emsdk install "${EMSCRIPTEN_VERSION}" \
    && /opt/emsdk/emsdk activate "${EMSCRIPTEN_VERSION}" \
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
