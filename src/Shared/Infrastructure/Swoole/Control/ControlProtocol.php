<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Control;

use App\Shared\Application\Port\ServerControlException;
use App\Shared\Application\Port\ServerControlResult;
use JsonException;

/**
 * Newline-delimited JSON on the control socket.
 *
 * Request:  {"id": "<correlation ID>", "op": "<operation>", "payload": {...}}
 * Answer:   {"id": ..., "results": [{"worker": 0, "data": ...}], "errors": [{"worker": 1, "error": "..."}], "missing": [2]}
 * Rejected: {"id": ... or null, "error": "..."}
 */
final class ControlProtocol
{
    /** Longest accepted line, including the newline. */
    public const int MAX_LINE_BYTES = 65536;

    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /** @param array<string, mixed> $payload */
    public static function encodeRequest(string $id, string $operation, array $payload): string
    {
        try {
            return json_encode(['id' => $id, 'op' => $operation, 'payload' => (object) $payload], self::JSON_FLAGS) . "\n";
        } catch (JsonException $exception) {
            throw new ServerControlException('the server control payload is not JSON-encodable: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @return array{id: string, op: string, payload: array<string, mixed>}
     *
     * @throws ServerControlException when the line is not a well-formed request
     */
    public static function decodeRequest(string $line): array
    {
        $request = self::decodeObject($line, 'request');
        $id = $request['id'] ?? null;
        $operation = $request['op'] ?? null;
        $payload = $request['payload'] ?? [];
        if (!is_string($id) || $id === '' || strlen($id) > 128) {
            throw new ServerControlException('malformed server control request: "id" must be a non-empty string');
        }
        if (!is_string($operation) || $operation === '') {
            throw new ServerControlException('malformed server control request: "op" must be a non-empty string');
        }
        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new ServerControlException('malformed server control request: "payload" must be an object');
        }

        /** @var array<string, mixed> $payload */
        return ['id' => $id, 'op' => $operation, 'payload' => $payload];
    }

    /** @throws ServerControlException when a worker's answer is not JSON-encodable */
    public static function encodeResult(string $id, ServerControlResult $result): string
    {
        $results = [];
        foreach ($result->results as $workerId => $data) {
            $results[] = ['worker' => $workerId, 'data' => $data];
        }
        $errors = [];
        foreach ($result->errors as $workerId => $error) {
            $errors[] = ['worker' => $workerId, 'error' => $error];
        }
        try {
            return json_encode(
                ['id' => $id, 'results' => $results, 'errors' => $errors, 'missing' => $result->missingWorkers],
                self::JSON_FLAGS,
            ) . "\n";
        } catch (JsonException $exception) {
            throw new ServerControlException('the server control answer is not JSON-encodable: ' . $exception->getMessage(), 0, $exception);
        }
    }

    public static function encodeError(?string $id, string $message): string
    {
        return json_encode(['id' => $id, 'error' => $message], self::JSON_FLAGS | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }

    /**
     * @throws ServerControlException when the server rejected the request or the answer is malformed
     */
    public static function decodeResponse(string $line, string $expectedId): ServerControlResult
    {
        $response = self::decodeObject($line, 'answer');
        $id = $response['id'] ?? null;
        // A request the server could not parse is rejected without an ID.
        if (array_key_exists('error', $response) && ($id === null || $id === $expectedId)) {
            throw new ServerControlException(is_string($response['error']) ? $response['error'] : 'the web server rejected the request');
        }
        if ($id !== $expectedId) {
            throw new ServerControlException('the web server answered a different server control request');
        }

        $results = [];
        foreach (self::entries($response, 'results') as $entry) {
            $results[self::worker($entry)] = $entry['data'] ?? null;
        }
        $errors = [];
        foreach (self::entries($response, 'errors') as $entry) {
            if (!is_string($entry['error'] ?? null)) {
                throw new ServerControlException('malformed server control answer: worker error without a message');
            }
            $errors[self::worker($entry)] = $entry['error'];
        }
        $missing = $response['missing'] ?? null;
        if (!is_array($missing) || !array_is_list($missing) || array_filter($missing, is_int(...)) !== $missing) {
            throw new ServerControlException('malformed server control answer: "missing" must list worker IDs');
        }

        /** @var list<int> $missing */
        return new ServerControlResult($results, $errors, $missing);
    }

    /** @return array<string, mixed> */
    private static function decodeObject(string $line, string $what): array
    {
        if (strlen($line) > self::MAX_LINE_BYTES) {
            throw new ServerControlException(sprintf('malformed server control %s: line too long', $what));
        }
        try {
            $decoded = json_decode(rtrim($line, "\r\n"), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ServerControlException(sprintf('malformed server control %s: %s', $what, $exception->getMessage()), 0, $exception);
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new ServerControlException(sprintf('malformed server control %s: expected a JSON object', $what));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return list<array<string, mixed>>
     */
    private static function entries(array $response, string $key): array
    {
        $entries = $response[$key] ?? null;
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new ServerControlException(sprintf('malformed server control answer: "%s" must be a list', $key));
        }
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new ServerControlException(sprintf('malformed server control answer: "%s" entries must be objects', $key));
            }
        }

        /** @var list<array<string, mixed>> $entries */
        return $entries;
    }

    /** @param array<string, mixed> $entry */
    private static function worker(array $entry): int
    {
        return is_int($entry['worker'] ?? null)
            ? $entry['worker']
            : throw new ServerControlException('malformed server control answer: entry without a worker ID');
    }
}
