<?php

declare(strict_types=1);

// Raw HTTP probes run next to app:serve on a disposable, isolated container network.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function exchange(string $wire): string
{
    $socket = stream_socket_client('tcp://127.0.0.1:9501', $errno, $error, 3);
    check($socket !== false, 'Cannot connect to the runtime.');
    stream_set_timeout($socket, 3);
    $offset = 0;
    while ($offset < strlen($wire)) {
        $written = fwrite($socket, substr($wire, $offset));
        check($written !== false && $written > 0, 'Request write failed.');
        $offset += $written;
    }
    $answer = stream_get_contents($socket, 65537);
    $metadata = stream_get_meta_data($socket);
    fclose($socket);
    check(!$metadata['timed_out'], 'HTTP rejection exceeded the socket deadline.');
    check($answer !== false && strlen($answer) <= 65536, 'Unbounded HTTP response.');

    return $answer;
}

function status(string $answer): int
{
    return preg_match('/^HTTP\/1\.[01] ([0-9]{3})/', $answer, $matches) === 1 ? (int) $matches[1] : 0;
}

function healthy(): void
{
    check(status(exchange("GET /live HTTP/1.1\r\nHost: baander.app\r\nConnection: close\r\n\r\n")) === 200, 'Server did not recover on a fresh connection.');
}

$kernel = new App\Kernel('prod', false);
$kernel->boot();
$connection = $kernel->getContainer()->get('doctrine')->getConnection();
$tables = ['users', 'oauth_access_tokens', 'oauth_refresh_tokens', 'password_reset_tokens', 'login_blocks'];
$snapshot = static function () use ($connection, $tables): array {
    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = $connection->fetchOne('SELECT count(*) FROM ' . $table);
    }

    return $counts;
};
$before = $snapshot();
$failures = [];
$cases = 0;
$probe = static function (string $name, string $wire, array $allowed) use (&$cases, &$failures): void {
    ++$cases;
    try {
        $answer = exchange($wire);
        check(in_array(status($answer), $allowed, true), $name . ' answered ' . status($answer));
        check(!str_contains($answer, 'security-runtime-secret-canary'), $name . ' reflected a credential');
        check(!preg_match('/(?:Stack trace|vendor\/|SQLSTATE|"access_token"|"refresh_token")/', $answer), $name . ' disclosed internals or credentials');
        healthy();
        echo $name . ": rejected; fresh connection healthy.\n";
    } catch (Throwable $error) {
        $failures[] = $error->getMessage();
        fwrite(STDERR, $error->getMessage() . "\n");
    }
};
$index = 0;
foreach (['{' , 'null', '42', '[]', '{"email":[],"password":"security-runtime-secret-canary"}', '{"email":"invalid","password":{}}', '{"email":"user@baander.app","password":{}}', '{"email":"user@baander.app","password":"security-runtime-secret-canary","totpCode":[]}', '{"username":[],"email":"user@baander.app"}'] as $body) {
    $probe('login JSON ' . ++$index, "POST /api/auth/login HTTP/1.1\r\nHost: baander.app\r\nContent-Type: application/json\r\nAccept: application/json\r\nConnection: close\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body, [400, 401, 422]);
}
foreach (['{', '{"email":[]}', '{"email":null}'] as $body) {
    $probe('reset JSON ' . ++$index, "POST /api/auth/password/reset-request HTTP/1.1\r\nHost: baander.app\r\nContent-Type: application/json\r\nAccept: application/json\r\nConnection: close\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body, [400, 422]);
}
$probe('invalid Content-Length', "POST /api/auth/login HTTP/1.1\r\nHost: baander.app\r\nContent-Length: invalid\r\nConnection: close\r\n\r\n", [0, 400]);
$probe('invalid request line', "GET /live NOTHTTP\r\nHost: baander.app\r\nConnection: close\r\n\r\n", [0, 400]);
$probe('oversized declared body', "POST /api/auth/login HTTP/1.1\r\nHost: baander.app\r\nContent-Length: 16777217\r\nConnection: close\r\n\r\n", [413]);
check($before === $snapshot(), 'Rejected input changed credential or user counts.');
$kernel->shutdown();
check($failures === [], implode('; ', $failures));
echo sprintf("Security runtime: %d rejection cases; credential/user counts unchanged.\n", $cases);
