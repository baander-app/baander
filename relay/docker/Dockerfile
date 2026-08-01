# =============================================================================
# Multi-stage Dockerfile for Baander Cloud Relay
# Stages: builder -> runtime
# Lightweight standalone service — no PostgreSQL, no Redis, just Swoole + SQLite.
# =============================================================================

# -----------------------------------------------------------------------------
# Stage 1: builder
# Compiles Swoole extension with SQLite support.
# -----------------------------------------------------------------------------
FROM php:8.4-zts-bookworm AS builder

RUN set -xe && \
    apt-get update -qq && \
    apt-get install -yqq -o=Dpkg::Use-Pty=0 \
        build-essential \
        git \
        libsqlite3-dev && \
    rm -rf /var/lib/apt/lists/*

# Install SQLite PDO extension
RUN docker-php-ext-install -j "$(nproc)" pdo_sqlite

# Install Swoole from source with SQLite support
RUN set -xe && \
    curl -fsSL 'https://github.com/swoole/swoole-src/archive/refs/tags/v6.0.0.tar.gz' | tar xz -C /tmp && \
    cd /tmp/swoole-src-* && \
    phpize && \
    ./configure \
        --enable-swoole-thread \
        --enable-swoole-sqlite \
        --enable-swoole-curl && \
    make -j "$(nproc)" && \
    make install && \
    docker-php-ext-enable swoole && \
    rm -rf /tmp/swoole-src-*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install application dependencies
COPY composer.json composer.lock* /app/
RUN cd /app && composer install --no-dev --no-scripts --prefer-dist --no-progress

# -----------------------------------------------------------------------------
# Stage 2: runtime
# Minimal image with PHP, Swoole, and the relay application.
# -----------------------------------------------------------------------------
FROM php:8.4-zts-bookworm AS runtime

# Install runtime dependencies
RUN set -xe && \
    apt-get update -qq && \
    apt-get install -yqq -o=Dpkg::Use-Pty=0 \
        libsqlite3-0 \
        ca-certificates && \
    rm -rf /var/lib/apt/lists/*

# Copy compiled extensions from builder
COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# Copy application
COPY --from=builder /app/vendor/ /app/vendor/
COPY . /app

# Create data directory for SQLite
RUN mkdir -p /app/data && chmod 777 /app/data

WORKDIR /app

EXPOSE 9502

# Run Swoole HTTP server via Symfony runtime
CMD ["php", "public/index.php"]
