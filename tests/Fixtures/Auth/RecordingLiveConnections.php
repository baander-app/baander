<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Shared\Application\Port\LiveConnectionsPortInterface;
use App\Shared\Domain\Model\Uuid;
use Throwable;

/**
 * The live-connections port of the production OAuth kernels: records every close
 * request as one JSON line in a file of the kernel's directory instead of reaching a
 * web server, which none of these tests runs. A child process that boots the same kernel
 * writes to the same file, so the test process reads what the child closed; each line
 * carries the writing process's pid. It answers with the count it was given and never
 * throws, so a revocation is never reported as a server fault by the recording itself.
 */
final readonly class RecordingLiveConnections implements LiveConnectionsPortInterface
{
    public const string FILE = 'live-connections.jsonl';

    public function __construct(private string $path)
    {
    }

    public static function path(string $directory): string
    {
        return $directory . '/' . self::FILE;
    }

    /** The size of the recording right now; a test takes it before the calls it wants to read. */
    public static function size(string $directory): int
    {
        $path = self::path($directory);
        clearstatcache(true, $path);

        return is_file($path) ? (int) filesize($path) : 0;
    }

    /**
     * The access token ids each closeForAccessTokens call of the process recorded after the
     * offset got, in call order.
     *
     * @return list<list<string>>
     */
    public static function closedAccessTokens(string $directory, int $pid, int $offset = 0): array
    {
        $payloads = [];
        foreach (self::records($directory, $offset) as $record) {
            if ($record['method'] === 'closeForAccessTokens' && $record['pid'] === $pid) {
                /** @var list<string> $ids */
                $ids = $record['access_token_ids'];
                $payloads[] = $ids;
            }
        }

        return $payloads;
    }

    /**
     * Every call recorded after the offset, by any process that booted a kernel over the directory.
     *
     * @return list<array<string, mixed>>
     */
    public static function records(string $directory, int $offset = 0): array
    {
        $path = self::path($directory);
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return [];
        }
        $records = [];
        foreach (explode("\n", trim((string) file_get_contents($path, offset: $offset))) as $line) {
            if ($line === '') {
                continue;
            }
            /** @var array<string, mixed> $record */
            $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $records[] = $record;
        }

        return $records;
    }

    public function closeForUser(Uuid $userId): int
    {
        $this->record(['method' => 'closeForUser', 'user_id' => $userId->toString()]);

        return 1;
    }

    public function closeForAccessTokens(array $accessTokenIds): int
    {
        $this->record(['method' => 'closeForAccessTokens', 'access_token_ids' => $accessTokenIds]);

        return count($accessTokenIds);
    }

    /** @param array<string, mixed> $record */
    private function record(array $record): void
    {
        try {
            $line = json_encode($record + ['pid' => getmypid(), 'at' => microtime(true)], JSON_THROW_ON_ERROR) . "\n";
            file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // The recording is evidence for a test, never a reason for the revocation to fail.
        }
    }
}
