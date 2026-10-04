# SPDX-License-Identifier: Apache-2.0
FROM debian:bookworm-slim@sha256:3783cc01769c7b2b1b83a5c5ad96c815348e28ed7da68e2e3687004faa906251 AS fetch
ARG TARGETARCH
RUN test "$TARGETARCH" = amd64
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates \
    && rm -rf /var/lib/apt/lists/*
RUN curl --fail --silent --show-error --location --max-time 180 \
      https://github.com/rqlite/rqlite/releases/download/v10.5.1/rqlite-v10.5.1-linux-amd64.tar.gz \
      -o /tmp/rqlite.tar.gz \
    && echo 'f0ebf593b573595022947add67cd22e6cbb02c1d2a1ed8c7da45c94093a49b0d  /tmp/rqlite.tar.gz' | sha256sum -c - \
    && mkdir /rqlite && tar -xzf /tmp/rqlite.tar.gz -C /rqlite --strip-components=1
FROM debian:bookworm-slim@sha256:3783cc01769c7b2b1b83a5c5ad96c815348e28ed7da68e2e3687004faa906251
RUN groupadd --gid 10001 registry && useradd --uid 10001 --gid 10001 --no-create-home registry \
    && mkdir /var/lib/rqlite && chown 10001:10001 /var/lib/rqlite
COPY --from=fetch /rqlite/rqlited /usr/local/bin/rqlited
COPY docker/rqlite-entrypoint.sh /usr/local/bin/rqlite-entrypoint
COPY third_party/rqlite-LICENSE.txt /usr/share/rqlite/LICENSE.txt
RUN chmod 0555 /usr/local/bin/rqlite-entrypoint
USER 10001:10001
EXPOSE 4001 4002
STOPSIGNAL SIGTERM
ENTRYPOINT ["/usr/local/bin/rqlite-entrypoint"]
