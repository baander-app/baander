<?php

declare(strict_types=1);

// Intentionally does not load Composer, Dotenv or the application kernel.
require dirname(__DIR__).'/src/Shared/Infrastructure/LogViewer/Sql/SqlStatement.php';
require dirname(__DIR__).'/src/Shared/Infrastructure/LogViewer/Sql/SnapshotQueryEngine.php';

use App\Shared\Infrastructure\LogViewer\Sql\SnapshotQueryEngine;

try {
    $request = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    $result = new SnapshotQueryEngine()->run($request['sql'], $request['records'], dirname($argv[1]).'/snapshot.sqlite');
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'kind' => 'invalid', 'message' => 'SQL query rejected: '.$error->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    exit(1);
}
