<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The cleanup command as a process of its own, with the log handlers of the environment it runs in.
 *
 * A test kernel logs to null handlers and a command tester captures only what the command writes,
 * so neither shows where a log record goes while the command prints its result.
 */
final class TranscodeCleanupConsoleOutputTest extends TestCase
{
    use MediaRuntimePersistenceHarness;

    /** @return iterable<string, array{string, string}> */
    public static function environments(): iterable
    {
        yield 'development log handlers' => ['dev', '1'];
        yield 'production log handlers' => ['prod', '0'];
    }

    #[DataProvider('environments')]
    public function testTheResultIsAllOfStdoutWhenTheRunLogsAnError(string $environment, string $debug): void
    {
        // One abandoned session holds its job. The database refuses to end the session, so the
        // run logs an error for it, carries on and finishes: the case in which a log record and
        // the result are written by the same successful process.
        $this->first->executeStatement("UPDATE transcode_jobs SET reference_count = 1, status = 'in_progress' WHERE id = ?", [$this->jobId->toString()]);
        $this->insertSession();
        $this->first->executeStatement("UPDATE transcode_sessions SET state = 'active', updated_at = clock_timestamp() - interval '2 days' WHERE id = ?", [$this->sessionId->toString()]);
        $this->first->executeStatement("CREATE FUNCTION refuse_session_expiry() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''The session row is not writable.''; END'");
        $this->first->executeStatement('CREATE TRIGGER refuse_session_expiry BEFORE UPDATE ON transcode_sessions FOR EACH ROW EXECUTE FUNCTION refuse_session_expiry()');

        $project = dirname(__DIR__, 2);
        $process = new Process(
            [PHP_BINARY, $project . '/bin/console', 'app:transcode:job:cleanup', '--json'],
            $project,
            [
                'APP_ENV' => $environment,
                'APP_DEBUG' => $debug,
                // The command reads the fixture schema; libpq applies this to every connection it opens.
                'PGOPTIONS' => '-c search_path=' . $this->schema . ',public',
            ],
            timeout: 300,
        );
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        // The whole of stdout decodes; a record written there would not.
        self::assertSame(['expired' => 0, 'cleaned' => 0], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        self::assertStringContainsString('A stale transcode session could not be expired.', $process->getErrorOutput());
        self::assertSame('active', $this->second->fetchOne('SELECT state FROM transcode_sessions WHERE id = ?', [$this->sessionId->toString()]));
        self::assertSame(1, (int) $this->second->fetchOne('SELECT count(*) FROM transcode_jobs'));
    }
}
