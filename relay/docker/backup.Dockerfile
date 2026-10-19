# SPDX-License-Identifier: Apache-2.0
FROM debian:bookworm-slim@sha256:3783cc01769c7b2b1b83a5c5ad96c815348e28ed7da68e2e3687004faa906251 AS fetch
ARG TARGETARCH
RUN test "$TARGETARCH" = amd64
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates unzip \
    && rm -rf /var/lib/apt/lists/*
RUN curl --fail --silent --show-error --location --max-time 180 \
      https://github.com/FiloSottile/age/releases/download/v1.3.2/age-v1.3.2-linux-amd64.tar.gz \
      -o /tmp/age.tar.gz \
    && echo 'cbe24006683f8eb669266162894b9a522a1af52f2665fbc63a4bb032ed26ac10  /tmp/age.tar.gz' | sha256sum -c - \
    && tar -xzf /tmp/age.tar.gz -C /tmp
RUN curl --fail --silent --show-error --location --max-time 180 \
      https://github.com/rclone/rclone/releases/download/v1.71.2/rclone-v1.71.2-linux-amd64.zip \
      -o /tmp/rclone.zip \
    && echo 'ab9fa5877cee91c64fdfd61a27028a458cf618b39259e5c371dc2ec34a12e415  /tmp/rclone.zip' | sha256sum -c - \
    && unzip -q /tmp/rclone.zip -d /tmp
FROM debian:bookworm-slim@sha256:3783cc01769c7b2b1b83a5c5ad96c815348e28ed7da68e2e3687004faa906251
RUN apt-get update && apt-get install -y --no-install-recommends python3 curl ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd --gid 10001 registry && useradd --uid 10001 --gid 10001 --no-create-home registry \
    && mkdir -p /var/lib/registry-backup /var/spool/registry-backup \
    && chown 10001:10001 /var/lib/registry-backup /var/spool/registry-backup \
    && chmod 0700 /var/lib/registry-backup /var/spool/registry-backup
COPY --from=fetch /tmp/age/age /usr/local/bin/age
COPY --from=fetch /tmp/age/age-keygen /usr/local/bin/age-keygen
COPY --from=fetch /tmp/rclone-v1.71.2-linux-amd64/rclone /usr/local/bin/rclone
COPY backup/registry_backup.py /usr/local/bin/registry-backup
COPY LICENSE.md /usr/share/registry-backup/LICENSE.md
COPY third_party/age-LICENSE.txt third_party/rclone-LICENSE.txt /usr/share/registry-backup/third_party/
USER 10001:10001
STOPSIGNAL SIGTERM
ENTRYPOINT ["python3", "/usr/local/bin/registry-backup"]
CMD ["run", "--config", "/etc/baander-registry-backup/backup.json"]
