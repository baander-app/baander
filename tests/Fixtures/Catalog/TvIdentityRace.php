<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Kernel;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Doctrine\Repository\LibraryFileIndexRepository;
use App\Library\Infrastructure\Doctrine\Repository\LibraryRepository;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Tests\Support\FinalSchemaBootstrap;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class TvIdentityRace
{
    public Kernel $kernel;
    public EntityManagerInterface $manager;
    public Connection $connection;
    public Connection $observer;
    public string $schema;
    public string $root;
    public Library $library;

    public function __construct()
    {
        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();
        $this->manager = $this->kernel->getContainer()->get('test.service_container')->get('doctrine')->getManager();
        $this->connection = $this->manager->getConnection();
        if ($this->connection->getTransactionNestingLevel() !== 0) {
            throw new \LogicException('The TV fixture requires committed cross-process observations.');
        }
        $this->schema = 'tv_ingest_' . bin2hex(random_bytes(8));
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        FinalSchemaBootstrap::bootstrap($this->connection);
        $this->observer = ArtistImportIdentityRace::connect($this->schema);
        $this->root = sys_get_temp_dir() . '/baander-tv-ingest-' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->root);
        $this->library = Library::create(
            'TV ingest fixture',
            new LibrarySlug('tv-ingest-fixture'),
            new LibraryPath($this->root),
            LibraryType::TvShow,
            FilesystemType::Local,
        );
        (new LibraryRepository($this->manager, new NativeClock()))->save($this->library);
    }

    public function close(): void
    {
        while ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->manager->clear();
        $this->connection->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        $this->connection->executeStatement('SET search_path TO public');
        $this->observer->close();
        (new Filesystem())->remove($this->root);
        $this->kernel->shutdown();
    }

    public function video(string $relativePath, string $color = 'black'): DiscoveredFile
    {
        $path = $this->root . '/' . $relativePath;
        (new Filesystem())->mkdir(dirname($path));
        $process = new Process([
            'ffmpeg', '-y', '-nostdin', '-loglevel', 'error', '-f', 'lavfi',
            '-i', 'color=c=' . $color . ':s=64x64:r=5:d=0.4',
            '-an', '-c:v', 'libx264', '-pix_fmt', 'yuv420p',
            '-metadata', 'comment=' . bin2hex(random_bytes(8)), '-f', 'mp4', $path,
        ]);
        $process->setTimeout(30);
        $process->mustRun();

        return $this->observe($relativePath);
    }

    public function observe(string $relativePath): DiscoveredFile
    {
        $path = $this->root . '/' . $relativePath;
        clearstatcache(true, $path);
        $hash = hash_file('xxh3', $path);
        if ($hash === false) {
            throw new \RuntimeException('Cannot hash the fixture video.');
        }
        $file = new DiscoveredFile(
            $path,
            $relativePath,
            pathinfo($path, PATHINFO_EXTENSION),
            (int) filesize($path),
            (int) filemtime($path),
            $hash,
        );
        (new LibraryFileIndexRepository($this->manager))->upsert(
            $this->library->getId(),
            $path,
            $hash,
            $file->size,
            $file->extension,
            $file->modifiedAt,
        );

        return $file;
    }

    public function message(DiscoveredFile ...$files): FilesDiscovered
    {
        return new FilesDiscovered($this->library->getId(), 'tv_show', dirname($files[0]->absolutePath), $files);
    }

    public function ingest(DiscoveredFile ...$files): void
    {
        $this->kernel->getContainer()->get('test.service_container')->get(FilesDiscoveredHandler::class)($this->message(...$files));
    }

    /** @return array{process: resource, output: resource, application: string} */
    public function start(FilesDiscovered $message): array
    {
        $application = 'tv_ingest_' . bin2hex(random_bytes(6));
        $output = tmpfile();
        if ($output === false) {
            throw new \RuntimeException('Cannot create TV contender output.');
        }
        $payload = [
            'library' => $message->libraryId->toString(),
            'directory' => $message->directory,
            'files' => array_map(static fn(DiscoveredFile $file): array => get_object_vars($file), $message->files),
        ];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/tv-identity-race.php', $this->schema, $application, json_encode($payload, JSON_THROW_ON_ERROR)],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        if (!is_resource($process)) {
            fclose($output);
            throw new \RuntimeException('Cannot start TV contender.');
        }

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }
}
