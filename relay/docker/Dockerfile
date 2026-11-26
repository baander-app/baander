# SPDX-License-Identifier: Apache-2.0
FROM debian:bookworm-slim@sha256:e67eeead736ff7bbe258ef088bff683635c561b1fcfefad0806c1df4ab49ce0e AS inventory-php
RUN apt-get update && apt-get install -y --no-install-recommends \
    autoconf build-essential ca-certificates curl pkg-config xz-utils \
    && rm -rf /var/lib/apt/lists/*
COPY docker/build-tooling-php.sh /tmp/build-tooling-php.sh
RUN sh /tmp/build-tooling-php.sh

FROM composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 AS inventory-loader
WORKDIR /tooling
COPY composer.json composer.lock ./
COPY tools ./tools
COPY tests/Support ./tests/Support
RUN composer install --no-dev --no-interaction --no-scripts --no-plugins --classmap-authoritative --ignore-platform-req=php

FROM debian:bookworm-slim@sha256:e67eeead736ff7bbe258ef088bff683635c561b1fcfefad0806c1df4ab49ce0e AS build
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential cmake ninja-build curl ca-certificates perl pkg-config \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /tmp
RUN curl --fail --silent --show-error --location --max-time 180 \
      https://github.com/openssl/openssl/releases/download/openssl-3.5.9/openssl-3.5.9.tar.gz \
      -o openssl.tar.gz \
    && echo '603f5602e2eef00d77fbd429d34dcd5822bb301757a1bc9cdb24c670f1eb859a  openssl.tar.gz' | sha256sum -c - \
    && tar -xzf openssl.tar.gz && cd openssl-3.5.9 \
    && ./Configure --prefix=/opt/openssl --libdir=lib no-shared no-tests \
    && make -j2 && make install_sw
WORKDIR /source
COPY CMakeLists.txt ./
COPY cpp ./cpp
COPY include ./include
RUN cmake -S . -B /build -G Ninja -DCMAKE_BUILD_TYPE=Release -DBUILD_TESTING=OFF \
      -DCMAKE_EXPORT_COMPILE_COMMANDS=ON \
      -DCMAKE_EXE_LINKER_FLAGS=-Wl,-Map,/build/baander-registry.map \
      -DOPENSSL_ROOT_DIR=/opt/openssl -DOPENSSL_USE_STATIC_LIBS=TRUE \
    && cmake --build /build --parallel 2
COPY --from=inventory-php /opt/php/bin/php /usr/local/bin/php
COPY --from=inventory-loader /tooling /tooling
RUN php /tooling/tools/packaged_inventory.php capture-api --build /build --output /build/api-build.json
FROM debian:bookworm-slim@sha256:e67eeead736ff7bbe258ef088bff683635c561b1fcfefad0806c1df4ab49ce0e AS runtime-base
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl libstdc++6 && rm -rf /var/lib/apt/lists/* \
    && groupadd --gid 10001 registry && useradd --uid 10001 --gid 10001 --no-create-home registry
COPY --from=build /build/baander-registry /usr/local/bin/baander-registry
COPY LICENSE.md /usr/share/baander-registry/LICENSE.md
COPY third_party/boost-LICENSE.txt third_party/json-LICENSE.txt third_party/openssl-LICENSE.txt /usr/share/baander-registry/third_party/
COPY --from=build /build/api-build.json /usr/share/registry-inventory/api-build.json
USER 10001:10001
EXPOSE 9502
STOPSIGNAL SIGTERM
ENTRYPOINT ["/usr/local/bin/baander-registry"]
CMD ["--config", "/etc/baander-registry/config.json"]

FROM debian:bookworm-slim@sha256:e67eeead736ff7bbe258ef088bff683635c561b1fcfefad0806c1df4ab49ce0e AS inventory
COPY --from=inventory-php /opt/php/bin/php /usr/local/bin/php
COPY --from=inventory-loader /tooling /tooling
COPY third_party/inventory /resolutions
COPY --from=runtime-base / /rootfs/
RUN php /tooling/tools/packaged_inventory.php generate --kind api --root /rootfs \
    --resolutions /resolutions --output /out

FROM runtime-base AS runtime
COPY --from=inventory /out/ /usr/share/registry-inventory/
