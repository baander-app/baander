#!/usr/bin/env bash

/usr/local/bin/php -d variables_order=EGPCS /var/www/html/artisan octane:start --watch --log-level=debug --host=0.0.0.0 --port=8000 --workers=auto --task-workers=auto --max-requests=250