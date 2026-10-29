ARG CI_IMAGE=baander-ci:local
ARG TOOLING_IMAGE=baander-tooling:local
ARG APP_HOME

FROM ${CI_IMAGE} AS application
FROM ${TOOLING_IMAGE}
ARG APP_HOME
RUN test -n "$APP_HOME"

RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg \
    && rm -rf /var/lib/apt/lists/*

COPY --from=application --chown=1000:1000 ${APP_HOME} /app
WORKDIR /app
USER 1000:1000

RUN ! command -v python && ! command -v python3 \
    && php -r 'foreach (["pcntl", "posix"] as $extension) { if (extension_loaded($extension)) { throw new RuntimeException("Unexpected extension: " . $extension); } }' \
    && php vendor/bin/phpunit --configuration phpunit-tooling.xml.dist \
        --do-not-cache-result --exclude-group registry-python-subject
