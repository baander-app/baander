<?php

declare(strict_types=1);

return [
    'source' => 'deploy/preset/README.md',
    'title' => 'Deploy preset',
    'description' => 'Start Bånder from a deployment preset: one JSON configuration sizes and runs the gateway, web server, PostgreSQL, Redis and the worker pair.',
    'opening' => 'This guide runs Bånder from a deployment preset: one JSON configuration and the <code>scripts/deploy-preset.php</code> command start a gateway, web server, PostgreSQL, a connection pooler, Redis and the managed worker pair. It is written for operators. The table at the end compares the size settings of the six example presets.',
];
