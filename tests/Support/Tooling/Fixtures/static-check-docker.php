#!/usr/bin/env php
<?php

declare(strict_types=1);

echo json_encode(
    [
        'argv' => array_slice($_SERVER['argv'], 1),
        'docker_host' => getenv('DOCKER_HOST'),
        'app_env' => getenv('APP_ENV'),
    ],
    JSON_THROW_ON_ERROR,
);
