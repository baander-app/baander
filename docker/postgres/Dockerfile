FROM postgres:18-bookworm AS base

# Set versions as build args for easy updates
ARG PGROONGA_VERSION=4.0.5-1
ARG POSTGRESQL_VERSION=18

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
    groonga-normalizer-mysql \
    groonga-token-filter-stem \
    groonga-tokenizer-mecab \
    # PostgreSQL contrib extensions
    postgresql-contrib-${POSTGRESQL_VERSION} \
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