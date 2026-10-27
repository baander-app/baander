# SPDX-License-Identifier: Apache-2.0
FROM debian:bookworm-slim@sha256:3783cc01769c7b2b1b83a5c5ad96c815348e28ed7da68e2e3687004faa906251 AS inventory-php
RUN apt-get update && apt-get install -y --no-install-recommends \
    autoconf build-essential ca-certificates curl pkg-config xz-utils \
    && rm -rf /var/lib/apt/lists/*
COPY docker/build-tooling-php.sh /tmp/build-tooling-php.sh
RUN sh /tmp/build-tooling-php.sh

FROM debian:bookworm-slim@sha256:3783cc01769c7b2b1b83a5c5ad96c815348e28ed7da68e2e3687004faa906251 AS fetch
ARG TARGETARCH
RUN test "$TARGETARCH" = amd64
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates git \
    && rm -rf /var/lib/apt/lists/*
RUN curl --fail --silent --show-error --location --max-time 180 \
      https://github.com/FiloSottile/age/releases/download/v1.3.2/age-v1.3.2-linux-amd64.tar.gz \
      -o /tmp/age.tar.gz \
    && echo 'cbe24006683f8eb669266162894b9a522a1af52f2665fbc63a4bb032ed26ac10  /tmp/age.tar.gz' | sha256sum -c - \
    && tar -xzf /tmp/age.tar.gz -C /tmp
RUN curl --fail --silent --show-error --location --max-time 180 \
      https://go.dev/dl/go1.25.3.linux-amd64.tar.gz -o /tmp/go.tar.gz \
    && echo '0335f314b6e7bfe08c3d0cfaa7c19db961b7b99fb20be62b0a826c992ad14e0f  /tmp/go.tar.gz' | sha256sum -c - \
    && tar -xzf /tmp/go.tar.gz -C /usr/local
WORKDIR /source
RUN git init && git remote add origin https://github.com/rclone/rclone.git \
    && git fetch --depth=1 origin refs/tags/v1.71.2:refs/tags/v1.71.2 \
    && git checkout --detach 84a85367111c84061c959e01e0c320618e492dc6 \
    && test "$(git rev-parse HEAD)" = 84a85367111c84061c959e01e0c320618e492dc6
RUN CGO_ENABLED=0 GOTOOLCHAIN=local /usr/local/go/bin/go build -p=2 -mod=readonly -trimpath -tags=cmount \
    -ldflags '-s -X github.com/rclone/rclone/fs.Version=v1.71.2' -o /out/rclone .
FROM debian:bookworm-slim@sha256:3783cc01769c7b2b1b83a5c5ad96c815348e28ed7da68e2e3687004faa906251 AS runtime-base
RUN apt-get update && apt-get install -y --no-install-recommends python3 curl ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd --gid 10001 registry && useradd --uid 10001 --gid 10001 --no-create-home registry \
    && mkdir -p /var/lib/registry-backup /var/spool/registry-backup \
    && chown 10001:10001 /var/lib/registry-backup /var/spool/registry-backup \
    && chmod 0700 /var/lib/registry-backup /var/spool/registry-backup
COPY --from=fetch /tmp/age/age /usr/local/bin/age
COPY --from=fetch /tmp/age/age-keygen /usr/local/bin/age-keygen
COPY --from=fetch /out/rclone /usr/local/bin/rclone
COPY backup/registry_backup.py /usr/local/bin/registry-backup
COPY LICENSE.md /usr/share/registry-backup/LICENSE.md
COPY third_party/age-LICENSE.txt third_party/rclone-LICENSE.txt /usr/share/registry-backup/third_party/
USER 10001:10001
STOPSIGNAL SIGTERM
ENTRYPOINT ["python3", "/usr/local/bin/registry-backup"]
CMD ["run", "--config", "/etc/baander-registry-backup/backup.json"]

FROM debian:bookworm-slim@sha256:3783cc01769c7b2b1b83a5c5ad96c815348e28ed7da68e2e3687004faa906251 AS inventory
COPY --from=inventory-php /opt/php/bin/php /usr/local/bin/php
COPY tools/packaged_inventory.php /tools/packaged_inventory.php
COPY tools/inventory /tools/inventory
COPY third_party/inventory /resolutions
COPY --from=runtime-base / /rootfs/
RUN php /tools/packaged_inventory.php generate --kind backup --root /rootfs \
    --resolutions /resolutions --output /out

FROM runtime-base
COPY --from=inventory /out/ /usr/share/registry-inventory/
