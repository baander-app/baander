# SPDX-License-Identifier: Apache-2.0
FROM debian:bookworm-slim@sha256:3783cc01769c7b2b1b83a5c5ad96c815348e28ed7da68e2e3687004faa906251 AS build
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential cmake ninja-build curl ca-certificates perl pkg-config python3 \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /tmp
RUN curl --fail --silent --show-error --location --max-time 180 \
      https://github.com/openssl/openssl/releases/download/openssl-3.5.3/openssl-3.5.3.tar.gz \
      -o openssl.tar.gz \
    && echo 'c9489d2abcf943cdc8329a57092331c598a402938054dc3a22218aea8a8ec3bf  openssl.tar.gz' | sha256sum -c - \
    && tar -xzf openssl.tar.gz && cd openssl-3.5.3 \
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
COPY tools/packaged_inventory.py /tools/packaged_inventory.py
RUN python3 /tools/packaged_inventory.py capture-api --build /build --output /build/api-build.json
FROM debian:bookworm-slim@sha256:3783cc01769c7b2b1b83a5c5ad96c815348e28ed7da68e2e3687004faa906251 AS runtime-base
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

FROM debian:bookworm-slim@sha256:3783cc01769c7b2b1b83a5c5ad96c815348e28ed7da68e2e3687004faa906251 AS inventory
RUN apt-get update && apt-get install -y --no-install-recommends python3 \
    && rm -rf /var/lib/apt/lists/*
COPY tools/packaged_inventory.py /tools/packaged_inventory.py
COPY third_party/inventory /resolutions
COPY --from=runtime-base / /rootfs/
RUN python3 /tools/packaged_inventory.py generate --kind api --root /rootfs \
    --resolutions /resolutions --output /out

FROM runtime-base AS runtime
COPY --from=inventory /out/ /usr/share/registry-inventory/
