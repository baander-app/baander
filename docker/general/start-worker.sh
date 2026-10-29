#!/bin/sh
set -eu

exec php "${APP_HOME:?APP_HOME is required}/bin/console" app:worker --no-interaction "$@"
