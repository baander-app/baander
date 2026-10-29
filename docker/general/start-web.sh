#!/bin/sh
set -eu

# Bind-mounted development source can invalidate generated Symfony classes.
# Only the web container owns this cache; workers use their immutable image.
if [ "${APP_ENV:-prod}" = dev ]; then
    rm -rf "${APP_HOME:?APP_HOME is required}/var/cache/dev"
fi

exec php "${APP_HOME:?APP_HOME is required}/bin/console" app:serve --no-interaction "$@"
