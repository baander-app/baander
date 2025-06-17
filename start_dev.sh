#!/usr/bin/env bash

# starts the web server and listens on the queue

/var/www/html/artisan octane:start --watch --log-level=debug --host=0.0.0.0 --port=8000 --workers=auto --task-workers=auto --max-requests=250