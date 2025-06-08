#!/usr/bin/env bash

export $(grep -v '^#' .env | xargs -0) && php artisan queue:listen