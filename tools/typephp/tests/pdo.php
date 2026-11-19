<?php

declare(strict_types=1);

function requirePdoCapability(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{string, ?string, ?string} */
function postgresCredentials(): array
{
    $dsn = getenv('TYPEPHP_TEST_PG_DSN');
    requirePdoCapability(
        is_string($dsn) && str_starts_with($dsn, 'pgsql:'),
        'Set TYPEPHP_TEST_PG_DSN to a disposable PostgreSQL PDO DSN; no database URL is inferred.',
    );

    return [
        $dsn,
        getenv('TYPEPHP_TEST_PG_USER') === false ? null : getenv('TYPEPHP_TEST_PG_USER'),
        getenv('TYPEPHP_TEST_PG_PASSWORD') === false ? null : getenv('TYPEPHP_TEST_PG_PASSWORD'),
    ];
}

function postgresConnection(): PDO
{
    [$dsn, $user, $password] = postgresCredentials();
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
    $connection = new PDO($dsn, $user, $password, $options);
    $connection->exec("SET TIME ZONE 'UTC'");
    $connection->exec("SET statement_timeout = '10s'");

    return $connection;
}

function qualifyPostgres(): void
{
    $connection = postgresConnection();
    $pid = $connection->pgsqlGetPid();
    requirePdoCapability(
        is_int($pid) && $pid === (int) $connection->query('SELECT pg_backend_pid()')->fetchColumn(),
        'PostgreSQL driver PID does not match the connected backend.',
    );
    $channel = 'baander_pdo_'.bin2hex(random_bytes(8));
    $connection->exec('LISTEN '.$channel);
    try {
        $notify = $connection->prepare('SELECT pg_notify(:channel, :payload)');
        $notify->execute(['channel' => $channel, 'payload' => 'baander PDO notification']);
        $notify->closeCursor();
        requirePdoCapability(
            $connection->pgsqlGetNotify(PDO::FETCH_ASSOC, 1000) === [
                'message' => $channel,
                'pid' => $pid,
                'payload' => 'baander PDO notification',
            ],
            'PostgreSQL notification channel, sender or payload did not round trip.',
        );
        requirePdoCapability(
            $connection->pgsqlGetNotify(PDO::FETCH_ASSOC, 0) === false,
            'PostgreSQL notification was delivered more than once.',
        );
    } finally {
        $connection->exec('UNLISTEN '.$channel);
    }
    $file = tempnam(sys_get_temp_dir(), 'baander-pdo-copy-');
    requirePdoCapability($file !== false, 'Could not create COPY fixture file.');
    try {
        $connection->beginTransaction();
        $connection->exec('CREATE TEMPORARY TABLE typephp_pdo_probe (id integer PRIMARY KEY, label text NOT NULL) ON COMMIT DROP');
        $insert = $connection->prepare('INSERT INTO typephp_pdo_probe (id, label) VALUES (:id, :label)');
        requirePdoCapability($insert->execute(['id' => 1, 'label' => 'prepared']), 'Named prepared INSERT failed.');
        $select = $connection->prepare('SELECT label FROM typephp_pdo_probe WHERE id = :id');
        $select->execute(['id' => 1]);
        requirePdoCapability($select->fetchColumn() === 'prepared', 'Named prepared SELECT returned the wrong value.');
        $select->closeCursor();

        requirePdoCapability(
            $connection->pgsqlCopyFromArray('typephp_pdo_probe', ["2\tarray\n"]),
            'COPY FROM array failed.',
        );
        $rows = $connection->pgsqlCopyToArray('typephp_pdo_probe');
        requirePdoCapability(is_array($rows), 'COPY TO array failed.');
        sort($rows);
        requirePdoCapability($rows === ["1\tprepared\n", "2\tarray\n"], 'COPY array round trip changed the rows.');
        requirePdoCapability(file_put_contents($file, "3\tfile\n") === 7, 'Could not write COPY input.');
        requirePdoCapability($connection->pgsqlCopyFromFile('typephp_pdo_probe', $file), 'COPY FROM file failed.');
        requirePdoCapability($connection->pgsqlCopyToFile('typephp_pdo_probe', $file), 'COPY TO file failed.');
        $rows = file($file);
        requirePdoCapability(is_array($rows), 'Could not read COPY output.');
        sort($rows);
        requirePdoCapability($rows === ["1\tprepared\n", "2\tarray\n", "3\tfile\n"], 'COPY file round trip changed the rows.');

        $oid = $connection->pgsqlLobCreate();
        requirePdoCapability(is_string($oid) && ctype_digit($oid), 'LOB creation failed.');
        $stream = $connection->pgsqlLobOpen($oid, 'wb');
        requirePdoCapability(is_resource($stream), 'Could not open LOB for writing.');
        $payload = "baander\0pdo\n";
        try {
            requirePdoCapability(fwrite($stream, $payload) === strlen($payload), 'LOB write was incomplete.');
        } finally {
            fclose($stream);
        }
        $stream = $connection->pgsqlLobOpen($oid, 'rb');
        requirePdoCapability(is_resource($stream), 'Could not reopen LOB for reading.');
        try {
            requirePdoCapability(stream_get_contents($stream) === $payload, 'LOB binary round trip changed the payload.');
        } finally {
            fclose($stream);
        }
        requirePdoCapability($connection->pgsqlLobUnlink($oid), 'LOB unlink failed.');
        $select = $connection->prepare('SELECT count(*) FROM pg_catalog.pg_largeobject_metadata WHERE oid = CAST(:oid AS oid)');
        $select->execute(['oid' => $oid]);
        requirePdoCapability((int) $select->fetchColumn() === 0, 'Unlinked LOB still exists.');
        $select->closeCursor();
    } finally {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        unlink($file);
    }
    qualifyPostgresCopyFailure($connection);
}

function qualifyPostgresCopyFailure(PDO $connection): void
{
    $connection->beginTransaction();
    try {
        $connection->exec('CREATE TEMPORARY TABLE typephp_pdo_copy_failure (id integer PRIMARY KEY, label text NOT NULL) ON COMMIT DROP');
        $failed = false;
        try {
            $connection->pgsqlCopyFromArray('typephp_pdo_copy_failure', ["invalid-integer\tcopy error\n"]);
        } catch (PDOException $exception) {
            requirePdoCapability($exception->getCode() === '22P02', 'Malformed COPY input returned an unexpected SQLSTATE.');
            $failed = true;
        }
        requirePdoCapability($failed, 'Malformed COPY input did not raise a PDO exception.');
    } finally {
        if ($connection->inTransaction()) {
            requirePdoCapability($connection->rollBack(), 'Could not roll back the failed COPY transaction.');
        }
    }
    requirePdoCapability(
        (int) $connection->query('SELECT 1')->fetchColumn() === 1,
        'PostgreSQL connection did not recover after COPY failure and rollback.',
    );
}

function qualifySqlite(): void
{
    $connection = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $connection->exec('CREATE TABLE pdo_probe (id integer PRIMARY KEY, label text NOT NULL)');
    $connection->beginTransaction();
    try {
        $insert = $connection->prepare('INSERT INTO pdo_probe (id, label) VALUES (:id, :label)');
        $insert->execute(['id' => 1, 'label' => 'committed']);
        $connection->commit();
        $connection->beginTransaction();
        $update = $connection->prepare('UPDATE pdo_probe SET label = :label WHERE id = :id');
        $update->execute(['id' => 1, 'label' => 'rolled back']);
        requirePdoCapability($connection->query('SELECT label FROM pdo_probe WHERE id = 1')->fetchColumn() === 'rolled back', 'SQLite did not read its transaction write.');
        $connection->rollBack();
        requirePdoCapability($connection->query('SELECT label FROM pdo_probe WHERE id = 1')->fetchColumn() === 'committed', 'SQLite commit/rollback did not preserve the expected value.');
    } finally {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
    }
}

function qualifyPostgresYield(): void
{
    $connection = postgresConnection();
    $timerFired = false;
    $peerRan = false;
    $peerFinished = new Swoole\Coroutine\Channel(1);
    $timer = Swoole\Timer::after(20, static function () use (&$timerFired): void {
        $timerFired = true;
    });
    requirePdoCapability($timer !== false, 'Could not schedule Swoole timer.');
    Swoole\Coroutine::create(static function () use (&$peerRan, $peerFinished): void {
        Swoole\Coroutine::sleep(0.02);
        $peerRan = true;
        $peerFinished->push(true);
    });
    try {
        $connection->query('SELECT pg_sleep(0.25)')->closeCursor();
        requirePdoCapability($timerFired && $peerRan, 'PostgreSQL query blocked the Swoole timer or peer coroutine.');
        requirePdoCapability($peerFinished->pop(5) === true, 'Peer coroutine did not finish.');
    } finally {
        if (!$timerFired) {
            Swoole\Timer::clear($timer);
        }
    }
}

foreach (['pgsql', 'sqlite'] as $driver) {
    requirePdoCapability(in_array($driver, PDO::getAvailableDrivers(), true), 'Required PDO driver is absent: '.$driver);
}
foreach (['SWOOLE_HOOK_PDO_PGSQL', 'SWOOLE_HOOK_PDO_SQLITE'] as $constant) {
    requirePdoCapability(defined($constant), 'Required Swoole hook is absent: '.$constant);
}
requirePdoCapability(class_exists(Swoole\Runtime::class), 'Swoole runtime is required.');
postgresCredentials();

$previousFlags = Swoole\Runtime::getHookFlags();
try {
    requirePdoCapability(Swoole\Runtime::setHookFlags(0), 'Swoole could not disable coroutine hooks.');
    [$dsn, $user, $password] = postgresCredentials();
    $connection = PDO::connect($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    requirePdoCapability((int) $connection->query('SELECT 1')->fetchColumn() === 1, 'PDO::connect query failed.');
    printf(
        "PDO::connect returned %s; modern Pdo\\Pgsql API %s; public notice callback %s.\n",
        get_debug_type($connection),
        $connection instanceof Pdo\Pgsql ? 'available' : 'unavailable',
        method_exists($connection, 'setNoticeCallback') ? 'available' : 'unavailable',
    );
    unset($connection);
    qualifyPostgres();
    qualifySqlite();
    echo "Synchronous PDO PostgreSQL public driver methods and SQLite checks passed.\n";

    $flags = SWOOLE_HOOK_PDO_PGSQL | SWOOLE_HOOK_PDO_SQLITE;
    requirePdoCapability(Swoole\Runtime::setHookFlags($flags), 'Swoole could not enable both PDO hooks.');
    requirePdoCapability((Swoole\Runtime::getHookFlags() & $flags) === $flags, 'Swoole did not enable both PDO hooks.');
    $failure = null;
    $completed = Swoole\Coroutine\run(static function () use (&$failure): void {
        try {
            qualifyPostgres();
            qualifySqlite();
            qualifyPostgresYield();
        } catch (Throwable $exception) {
            $failure = $exception;
        }
    });
    if ($failure !== null) {
        throw $failure;
    }
    requirePdoCapability($completed, 'Swoole coroutine scheduler did not complete.');
    echo "Coroutine PDO PostgreSQL public driver methods, SQLite and scheduler checks passed.\n";
} finally {
    Swoole\Runtime::setHookFlags($previousFlags);
}
