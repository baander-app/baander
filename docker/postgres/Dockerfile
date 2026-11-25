FROM postgres:18-trixie@sha256:c699303802144847d238196173655dbbecf7a96aeca1565f2b02eb671a1f0b6b AS postgres-dist

# Builds the launcher that starts PostgreSQL with transparent huge pages
# disabled; see postgres/thp-disabled-exec.c.
FROM postgres-dist AS thp-helper
RUN apt-get update && apt-get install -y --no-install-recommends gcc libc6-dev \
    && rm -rf /var/lib/apt/lists/*
COPY ./postgres/thp-disabled-exec.c /src/thp-disabled-exec.c
RUN gcc -O2 -Wall -Wextra -Werror -o /thp-disabled-exec /src/thp-disabled-exec.c

FROM postgres-dist AS base

# Set versions as build args for easy updates
ARG PGROONGA_VERSION=4.1.0-1
ARG POSTGRESQL_VERSION=18
ARG PGBOUNCER_VERSION=1.26.1-1.pgdg13+1
ARG ARROW_APT_SOURCE_VERSION=26.0.1-1
ARG ARROW_APT_SOURCE_SHA256=4a83b9916a242e0267848f29bbb81303dac7ef1f2de654bf997194aefe693aaf
ARG GROONGA_APT_SOURCE_VERSION=2026.04.28-1
ARG GROONGA_APT_SOURCE_SHA256=3406de4b8965c44a0e793090efbb0996a1802930159e7aa3f31c97dbef127c34

ENV PGROONGA_VERSION=${PGROONGA_VERSION}
ENV POSTGRESQL_VERSION=${POSTGRESQL_VERSION}

# Install build dependencies and PostgreSQL extensions
RUN apt-get update && apt-get install -y -V \
    lsb-release \
    wget \
    gnupg \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Add the Apache Arrow repository. The apt-source package installs the signing
# key apt then checks every Arrow package against, so it is pinned by version
# and SHA-256 for the base image's Debian release.
RUN wget -q -O /tmp/apache-arrow-apt-source.deb "https://apache.jfrog.io/artifactory/arrow/debian/pool/trixie/main/a/apache-arrow-apt-source/apache-arrow-apt-source_${ARROW_APT_SOURCE_VERSION}_all.deb" \
    && echo "${ARROW_APT_SOURCE_SHA256}  /tmp/apache-arrow-apt-source.deb" | sha256sum -c - \
    && apt-get install -y -V /tmp/apache-arrow-apt-source.deb \
    && rm /tmp/apache-arrow-apt-source.deb

# Add the Groonga repository, pinned the same way.
RUN wget -q -O /tmp/groonga-apt-source.deb "https://packages.groonga.org/debian/pool/trixie/main/g/groonga-apt-source/groonga-apt-source_${GROONGA_APT_SOURCE_VERSION}_all.deb" \
    && echo "${GROONGA_APT_SOURCE_SHA256}  /tmp/groonga-apt-source.deb" | sha256sum -c - \
    && apt-get install -y -V /tmp/groonga-apt-source.deb \
    && rm /tmp/groonga-apt-source.deb

# Update package lists and install extensions
RUN apt-get update && apt-get install -y -V \
    # PGroonga and dependencies
    postgresql-${POSTGRESQL_VERSION}-pgdg-pgroonga=${PGROONGA_VERSION} \
    groonga-token-filter-stem \
    groonga-tokenizer-mecab \
    # PostgreSQL contrib extensions
    postgresql-contrib-${POSTGRESQL_VERSION} \
    # Transaction-mode connection pooler, run as its own service from this image
    pgbouncer=${PGBOUNCER_VERSION} \
    # Python support for complex processing
    postgresql-plpython3-${POSTGRESQL_VERSION} \
    python3-dev \
    python3-pip \
    # Python packages via apt (preferred over pip for system packages)
    python3-mutagen \
    python3-pil \
    python3-numpy \
    # Cleanup
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Copy configuration files - correct path relative to build context (./docker/)
COPY ./postgres/conf/ /etc/postgresql/

# The pooler service runs baander-pooler; baander-pooler-credentials prepares
# its auth file before it starts.
COPY ./postgres/pooler/pgbouncer.ini /etc/baander-pooler/pgbouncer.ini
COPY --chmod=0755 ./postgres/pooler/baander-pooler ./postgres/pooler/baander-pooler-credentials /usr/local/bin/

# PGroonga backends touch large anonymous mappings sparsely; with host THP mode
# "always" each touched 2 MiB range can become a resident huge page, so every
# process in this container runs with THP disabled. hugetlbfs pages selected by
# the huge_pages setting are unaffected.
COPY --from=thp-helper /thp-disabled-exec /usr/local/bin/thp-disabled-exec
ENTRYPOINT ["thp-disabled-exec", "docker-entrypoint.sh"]
CMD ["postgres"]
