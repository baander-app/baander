<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Runs scripts/e2e-ingest-video.php and app:e2e:ingest-video as real child
 * processes. Their writes are committed, so this test removes them itself.
 */
final class E2EIngestVideoScriptTest extends TestCase
{
    private const string SLUG = 'e2e-test-movies';

    private string $project;
    private string $directory;
    private Connection $database;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL and DATABASE_URL to the same fully migrated disposable PostgreSQL database.');
        }

        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $params['serverVersion'] = '18';
        $this->database = DriverManager::getConnection($params);
        self::assertFalse(
            $this->database->fetchOne('SELECT id FROM libraries WHERE slug = ?', [self::SLUG]),
            'The disposable database must not already contain the e2e-test-movies library.',
        );

        $this->project = dirname(__DIR__, 2);
        $this->directory = sys_get_temp_dir() . '/baander-e2e-ingest-' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->directory . '/Fixture Movie');
        $this->runChecked([
            'ffmpeg', '-nostdin', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc=duration=1:size=64x64:rate=5',
            '-pix_fmt', 'yuv420p', $this->directory . '/Fixture Movie/clip.mp4',
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->database)) {
            return;
        }

        $libraryId = $this->database->fetchOne('SELECT id FROM libraries WHERE slug = ?', [self::SLUG]);
        if ($libraryId !== false) {
            $this->database->executeStatement(
                'DELETE FROM videos WHERE id IN (SELECT mv.video_id FROM movie_video mv JOIN movies m ON m.id = mv.movie_id WHERE m.library_id = ?)',
                [$libraryId],
            );
            $this->database->executeStatement('DELETE FROM library_file_index WHERE library_id = ?', [$libraryId]);
            $this->database->executeStatement('DELETE FROM libraries WHERE id = ?', [$libraryId]);
        }
        $this->database->close();
        (new Filesystem())->remove($this->directory);
    }

    public function testScriptExitsWithHintWhenTheLibraryIsMissing(): void
    {
        $process = $this->script();

        self::assertSame(1, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('', $process->getOutput());
        self::assertStringContainsString("Library 'e2e-test-movies' not found. Create it first with:", $process->getErrorOutput());
        self::assertStringContainsString("php bin/console app:library:create 'E2E Test Movies'", $process->getErrorOutput());
    }

    public function testScriptPrintsTheLibraryAndVideoIdsTheCommandIngested(): void
    {
        $command = $this->runChecked(['php', 'bin/console', 'app:e2e:ingest-video', $this->directory, '--no-ansi', '--no-interaction']);
        $libraryId = $this->database->fetchOne('SELECT id FROM libraries WHERE slug = ?', [self::SLUG]);
        self::assertIsString($libraryId);
        self::assertStringContainsString(sprintf('Created library "E2E Test Movies" (%s)', $libraryId), $command->getOutput());
        self::assertSame(1, preg_match_all('/^ \* ([0-9a-f-]{36})$/m', $command->getOutput(), $listed), $command->getOutput());

        $script = $this->script();

        self::assertSame(0, $script->getExitCode(), $script->getErrorOutput());
        self::assertSame(
            ['libraryId' => $libraryId, 'videoIds' => $listed[1]],
            json_decode($script->getOutput(), true, 8, JSON_THROW_ON_ERROR),
        );
        self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM libraries WHERE slug = ?', [self::SLUG]));
    }

    private function script(): Process
    {
        $process = new Process(['php', 'scripts/e2e-ingest-video.php'], $this->project);
        $process->setTimeout(120);
        $process->run();

        return $process;
    }

    /**
     * @param list<string> $command
     */
    private function runChecked(array $command): Process
    {
        $process = new Process($command, $this->project);
        $process->setTimeout(120);
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput() . $process->getOutput());

        return $process;
    }
}
