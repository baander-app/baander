ARG QUALIFICATION_BASE=baander-ci:local
FROM ${QUALIFICATION_BASE}

USER root
COPY composer.lock /tmp/typephp-application.lock
RUN cmp /tmp/typephp-application.lock /var/www/html/composer.lock \
    && apt-get update && apt-get install -y --no-install-recommends \
        libffi8 libonig5 libsodium23 \
    && rm -rf /var/lib/apt/lists/* \
    && find /var/www/html -mindepth 1 -maxdepth 1 ! -name vendor -exec rm -rf -- {} +

COPY --chown=www-data:www-data . /var/www/html/
WORKDIR /var/www/html
RUN mkdir -p config/secrets/oauth var coverage \
    && umask 077 \
    && openssl genrsa -out config/secrets/oauth/private.key 2048 2>/dev/null \
    && openssl rsa -in config/secrets/oauth/private.key -pubout -out config/secrets/oauth/public.key 2>/dev/null \
    && umask 022 \
    && composer dump-autoload --dev --optimize --no-scripts --no-interaction \
    && composer tooling:autoload \
    && mkdir -p /usr/local/lib/typephp /usr/local/etc/typephp/conf.d \
    && php tools/typephp/dump-test-support.php /var/www/html /usr/local/lib/typephp/test-autoload.php \
    && rm /usr/local/bin/php \
    && chown -R www-data:www-data var coverage config/secrets

COPY --from=typephp-artifacts /baander /usr/local/lib/typephp/
COPY tools/typephp/test-support.php /usr/local/lib/typephp/test-support.php
COPY tools/typephp/qualification.ini /usr/local/etc/typephp/qualification.ini
RUN chmod 0755 /usr/local/lib/typephp/baander \
    && ln -s /usr/local/lib/typephp/baander /usr/local/bin/php
ENV PHPRC=/usr/local/etc/typephp/qualification.ini \
    PHP_INI_SCAN_DIR=/usr/local/etc/typephp/conf.d \
    BAANDER_TYPEPHP_QUALIFICATION=1
USER www-data
