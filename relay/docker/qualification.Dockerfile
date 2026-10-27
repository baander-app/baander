# SPDX-License-Identifier: Apache-2.0
ARG BACKUP_IMAGE=baander-registry-backup:qualification
ARG API_IMAGE=baander-registry:qualification
ARG DATABASE_IMAGE=baander-registry-rqlite:qualification
FROM debian:bookworm-slim@sha256:3783cc01769c7b2b1b83a5c5ad96c815348e28ed7da68e2e3687004faa906251 AS qualification-php
RUN apt-get update && apt-get install -y --no-install-recommends \
    autoconf build-essential ca-certificates curl pkg-config xz-utils libssl-dev libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/*
COPY docker/build-qualification-php.sh /tmp/build-qualification-php.sh
RUN sh /tmp/build-qualification-php.sh
FROM ${API_IMAGE} AS api
FROM ${DATABASE_IMAGE} AS database
FROM ${BACKUP_IMAGE} AS qualification
USER root
RUN apt-get update && apt-get install -y --no-install-recommends \
    libstdc++6 libssl3 libsqlite3-0 openssl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=qualification-php /opt/php /opt/php
COPY --from=api /usr/local/bin/baander-registry /usr/local/bin/baander-registry
COPY --from=database /usr/local/bin/rqlited /usr/local/bin/rqlited
COPY composer.json composer.lock /qualification/
COPY vendor /qualification/vendor
COPY tools/inventory /qualification/tools/inventory
COPY tests /qualification/tests
COPY backup/registry_backup.py /qualification/backup/registry_backup.py
ENV PATH=/opt/php/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
USER 10001:10001
WORKDIR /qualification
ENTRYPOINT ["php"]
