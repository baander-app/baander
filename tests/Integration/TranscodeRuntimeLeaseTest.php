<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Transcode\Application\DTO\TranscodeExecutionClaim;
use PHPUnit\Framework\TestCase;

final class TranscodeRuntimeLeaseTest extends TestCase
{
    use MediaRuntimePersistenceHarness;

    public function testExpiredOwnerCannotRenewPublishFailReleaseOrSaveOverSuccessor(): void
    {
        $this->admit();
        $old = $this->runtime->claim($this->jobId, $this->worker());
        self::assertNotNull($old);
        self::assertTrue($old->runtime->ownershipLive);
        self::assertTrue($this->runtime->observe($old, 'accepted'));
        self::assertNull($this->runtime->claim($this->jobId, $this->worker('b')));
        $this->first->executeStatement("UPDATE transcode_runtime_state SET lease_expires_at = clock_timestamp() - interval '1 second'");
        self::assertFalse($this->runtime->find($this->jobId)->ownershipLive);
        $new = $this->runtimeFor($this->second)->claim($this->jobId, $this->worker('b'));
        self::assertNotNull($new);
        self::assertGreaterThan($old->runtime->generation, $new->runtime->generation);
        self::assertFalse($this->runtime->renew($old));
        self::assertFalse($this->runtime->observe($old, 'ready', '/fixture/generation-' . $old->runtime->generation));
        self::assertFalse($this->runtime->observe($old, 'failed', error: 'stale error'));
        self::assertFalse($this->runtime->release($old));
        self::assertNull($this->runtime->refresh($old));
        self::assertFalse($this->runtime->withOwnership($old, static function (): void { self::fail('A stale owner cannot save aggregates.'); }));
        self::assertTrue($this->runtime->observe($new, 'ready', '/fixture/generation-' . $new->runtime->generation));
        $forged = new TranscodeExecutionClaim($new->runtime, $this->worker(), $new->token);
        self::assertFalse($this->runtime->renew($forged), 'Matching token alone cannot impersonate a different validated launch.');
    }

    public function testExpiryIsEvaluatedAfterWaitingForTheRowLock(): void
    {
        $this->admit();
        $claim = $this->runtime->claim($this->jobId, $this->worker());
        self::assertNotNull($claim);
        $this->first->executeStatement("UPDATE transcode_runtime_state SET lease_expires_at = clock_timestamp() + interval '1 second'");
        $marker = sys_get_temp_dir() . '/baander-media-lock-' . bin2hex(random_bytes(12));
        $output = tmpfile();
        $code = <<<'CODE'
require $argv[1];
$params = (new \Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql']))->parse(getenv('OUTBOX_TEST_DATABASE_URL'));
$connection = \Doctrine\DBAL\DriverManager::getConnection($params);
$connection->executeStatement('SET search_path TO ' . $argv[2] . ', public');
$connection->beginTransaction();
$connection->executeQuery('SELECT job_id FROM transcode_runtime_state FOR UPDATE')->free();
file_put_contents($argv[3], 'locked');
usleep(1300000);
$connection->commit();
CODE;
        $process = proc_open([PHP_BINARY, '-r', $code, '--', dirname(__DIR__, 2) . '/vendor/autoload.php', $this->schema, $marker], [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output], $pipes);
        self::assertIsResource($process);
        try {
            $deadline = microtime(true) + 3;
            while (!is_file($marker)) {
                if (microtime(true) >= $deadline) {
                    rewind($output);
                    self::fail('Locker failed to start: ' . stream_get_contents($output));
                }
                usleep(1000);
            }
            self::assertTrue((bool) $this->second->fetchOne('SELECT lease_expires_at > clock_timestamp() FROM transcode_runtime_state'));
            self::assertFalse($this->runtime->renew($claim));
            self::assertSame(0, proc_close($process));
        } finally {
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
            fclose($output);
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }

    public function testFailedAggregateEffectRollsBackAndKeepsClaimUsable(): void
    {
        $this->admit();
        $claim = $this->runtime->claim($this->jobId, $this->worker());
        self::assertNotNull($claim);
        try {
            $this->runtime->withOwnership($claim, function (): void {
                $this->first->executeStatement('UPDATE transcode_jobs SET completed_segments = 5');
                throw new \RuntimeException('flush failed');
            });
            self::fail('Effect failure must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('flush failed', $error->getMessage());
        }
        self::assertSame(0, (int) $this->second->fetchOne('SELECT completed_segments FROM transcode_jobs'));
        self::assertTrue($this->runtime->renew($claim));
    }
}
