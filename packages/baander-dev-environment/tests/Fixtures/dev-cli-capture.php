<?php

declare(strict_types=1);

file_put_contents(
    getenv('CAPTURE_RESULT'),
    json_encode([array_slice($_SERVER['argv'], 1), getenv('cmd')], JSON_THROW_ON_ERROR),
);
