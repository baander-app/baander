# Builds the launcher that starts PostgreSQL with transparent huge pages
# disabled; see postgres/thp-disabled-exec.c.
FROM postgres:18-trixie AS thp-helper
RUN apt-get update && apt-get install -y --no-install-recommends gcc libc6-dev \
    && rm -rf /var/lib/apt/lists/*
COPY ./postgres/thp-disabled-exec.c /src/thp-disabled-exec.c
RUN gcc -O2 -Wall -Wextra -Werror -o /thp-disabled-exec /src/thp-disabled-exec.c

FROM postgres:18-trixie AS base

# Set versions as build args for easy updates
ARG PGROONGA_VERSION=4.0.5-1
ARG POSTGRESQL_VERSION=18
ARG PGBOUNCER_VERSION=1.26.1-1.pgdg13+1

ENV PGROONGA_VERSION=${PGROONGA_VERSION}
ENV POSTGRESQL_VERSION=${POSTGRESQL_VERSION}

# Install build dependencies and PostgreSQL extensions
RUN apt-get update && apt-get install -y -V \
    lsb-release \
    wget \
    gnupg \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Add Apache Arrow repository
RUN wget -q https://apache.jfrog.io/artifactory/arrow/debian/apache-arrow-apt-source-latest-$(lsb_release --codename --short).deb \
    && apt-get install -y -V ./apache-arrow-apt-source-latest-$(lsb_release --codename --short).deb \
    && rm apache-arrow-apt-source-latest-$(lsb_release --codename --short).deb

# Add Groonga repository
RUN wget -q https://packages.groonga.org/debian/groonga-apt-source-latest-$(lsb_release --codename --short).deb \
    && apt-get install -y -V ./groonga-apt-source-latest-$(lsb_release --codename --short).deb \
    && rm groonga-apt-source-latest-$(lsb_release --codename --short).deb

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
