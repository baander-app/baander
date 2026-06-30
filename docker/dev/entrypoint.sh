#!/bin/sh
set -e

# Disable Xdebug at runtime — conflicts with Swoole coroutines.
# Keeps xdebug installed so `debug-on` / `debug-off` aliases can re-enable it.
# Skip disabling if XDEBUG_MODE=coverage is set (for CI coverage collection).
XDEBUG_INI="/usr/local/etc/php/conf.d/xdebug.ini"
EXT_INI="/usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini"

if [ "$XDEBUG_MODE" != "coverage" ]; then
    # Comment out the zend_extension line in docker-php-ext-xdebug.ini
    # (we can't mv because the directory is owned by root)
    if [ -f "$EXT_INI" ] && grep -q '^zend_extension' "$EXT_INI"; then
        sed -i 's/^zend_extension/;zend_extension/' "$EXT_INI"
        echo "[entrypoint] Disabled xdebug extension in ${EXT_INI}"
    fi

    # Set xdebug.mode = off to disable all xdebug functionality
    if [ -f "$XDEBUG_INI" ] && grep -q '^xdebug\.mode' "$XDEBUG_INI"; then
        sed -i 's/^xdebug\.mode.*/xdebug.mode = off/' "$XDEBUG_INI"
        echo "[entrypoint] Set xdebug.mode = off in ${XDEBUG_INI}"
    fi
else
    echo "[entrypoint] XDEBUG_MODE=coverage set, keeping Xdebug enabled"
fi

exec "$@"
