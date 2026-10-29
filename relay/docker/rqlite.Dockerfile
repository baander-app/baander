# SPDX-License-Identifier: Apache-2.0
FROM debian:bookworm-slim@sha256:3783cc01769c7b2b1b83a5c5ad96c815348e28ed7da68e2e3687004faa906251 AS inventory-php
RUN apt-get update && apt-get install -y --no-install-recommends \
    autoconf build-essential ca-certificates curl pkg-config xz-utils \
    && rm -rf /var/lib/apt/lists/*
COPY docker/build-tooling-php.sh /tmp/build-tooling-php.sh
RUN sh /tmp/build-tooling-php.sh

FROM composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc AS inventory-loader
WORKDIR /tooling
COPY composer.json composer.lock ./
COPY tools ./tools
COPY tests/Support ./tests/Support
RUN composer install --no-dev --no-interaction --no-scripts --no-plugins --classmap-authoritative --ignore-platform-req=php

FROM debian:bookworm-slim@sha256:3783cc01769c7b2b1b83a5c5ad96c815348e28ed7da68e2e3687004faa906251 AS build
ARG TARGETARCH
RUN test "$TARGETARCH" = amd64
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates git build-essential \
    && rm -rf /var/lib/apt/lists/*
RUN curl --fail --silent --show-error --location --max-time 180 \
      https://go.dev/dl/go1.27.1.linux-amd64.tar.gz -o /tmp/go.tar.gz \
    && echo '63d339f0da5ab53635a56f2490a7984dfe12dfcff22ad749f63edaf590168445  /tmp/go.tar.gz' | sha256sum -c - \
    && tar -xzf /tmp/go.tar.gz -C /usr/local
WORKDIR /source
RUN git init && git remote add origin https://github.com/rqlite/rqlite.git \
    && git fetch --depth=1 origin refs/tags/v10.5.1:refs/tags/v10.5.1 \
    && git checkout --detach 8b6cc4cb14a78b81ac725ec3c7cd281e1c646422 \
    && test "$(git rev-parse HEAD)" = 8b6cc4cb14a78b81ac725ec3c7cd281e1c646422
RUN CGO_ENABLED=1 GOTOOLCHAIN=local /usr/local/go/bin/go build -p=2 -mod=readonly -trimpath \
    -ldflags '-w -s -X github.com/rqlite/rqlite/v10/cmd.CompilerCommand=gcc -X github.com/rqlite/rqlite/v10/cmd.Version=v10.5.1 -X github.com/rqlite/rqlite/v10/cmd.Commit=8b6cc4cb14a78b81ac725ec3c7cd281e1c646422 -X github.com/rqlite/rqlite/v10/cmd.Buildtime=2026-10-02T14:52:00Z' \
    -o /out/rqlited ./cmd/rqlited
FROM debian:bookworm-slim@sha256:3783cc01769c7b2b1b83a5c5ad96c815348e28ed7da68e2e3687004faa906251 AS runtime-base
RUN groupadd --gid 10001 registry && useradd --uid 10001 --gid 10001 --no-create-home registry \
    && mkdir /var/lib/rqlite && chown 10001:10001 /var/lib/rqlite
COPY --from=build /out/rqlited /usr/local/bin/rqlited
COPY docker/rqlite-entrypoint.sh /usr/local/bin/rqlite-entrypoint
COPY third_party/rqlite-LICENSE.txt /usr/share/rqlite/LICENSE.txt
RUN chmod 0555 /usr/local/bin/rqlite-entrypoint
USER 10001:10001
EXPOSE 4001 4002
STOPSIGNAL SIGTERM
ENTRYPOINT ["/usr/local/bin/rqlite-entrypoint"]

FROM debian:bookworm-slim@sha256:3783cc01769c7b2b1b83a5c5ad96c815348e28ed7da68e2e3687004faa906251 AS inventory
COPY --from=inventory-php /opt/php/bin/php /usr/local/bin/php
COPY --from=inventory-loader /tooling /tooling
COPY third_party/inventory /resolutions
COPY --from=runtime-base / /rootfs/
RUN php /tooling/tools/packaged_inventory.php generate --kind rqlite --root /rootfs \
    --resolutions /resolutions --output /out

FROM runtime-base
COPY --from=inventory /out/ /usr/share/registry-inventory/
