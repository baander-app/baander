#!/usr/bin/env bash

cd /var/www/html

/usr/bin/composer du
php artisan optimize:clear
php artisan clear-compiled
php artisan setup:dev --fresh