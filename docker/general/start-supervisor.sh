#!/bin/sh
set -eu

# Clear generated dev classes before any supervised process can load them.
# Child restarts must never delete a cache shared with another running worker.
if [ "${APP_ENV:-prod}" = dev ]; then
    rm -rf /var/www/html/var/cache/dev
fi
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
