<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\TvSeriesEntity;
use App\Catalog\Infrastructure\Doctrine\Repository\VideoMatchStateRepository;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use RuntimeException;

final class VideoMatchQueue
{
    public readonly string $schema;
    public readonly Connection $writer;
    public readonly Connection $observer;
    public readonly EntityManager $manager;
    public readonly Uuid $movieId;
    public readonly Uuid $seriesId;
    public readonly VideoMatchStateRepository $states;

    public function __construct()
    {
        $this->schema = 'video_queue_' . bin2hex(random_bytes(8));
        $this->writer = ArtistImportIdentityRace::connect('public');
        $this->writer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->writer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach (['libraries', 'movies', 'videos', 'movie_video', 'tv_series', 'video_match_states', 'video_provider_pauses'] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->writer, $table);
        }
        $this->writer->executeStatement('ALTER TABLE video_match_states ADD CONSTRAINT fk_video_match_states_movie_id FOREIGN KEY (movie_id) REFERENCES movies (id) ON DELETE CASCADE');
        $this->writer->executeStatement('ALTER TABLE video_match_states ADD CONSTRAINT fk_video_match_states_series_id FOREIGN KEY (series_id) REFERENCES tv_series (id) ON DELETE CASCADE');
        $this->manager = ArtistImportIdentityRace::entityManager($this->writer);
        $movies = new LibraryEntity('Movies', 'queue-movies', '/media/movies', 'movie', 'local');
        $television = new LibraryEntity('TV', 'queue-tv', '/media/tv', 'tv_show', 'local');
        $this->manager->persist($movies);
        $this->manager->persist($television);
        $this->manager->flush();
        $movie = new MovieEntity(new PublicId(), $movies, 'Heat');
        $series = new TvSeriesEntity($television, TvSeries::create($television->getId(), 'Series', 'Series')->getState());
        $this->manager->persist($movie);
        $this->manager->persist($series);
        $this->manager->flush();
        $this->movieId = $movie->getId();
        $this->seriesId = $series->id;
        $this->states = new VideoMatchStateRepository($this->writer);
        $this->observer = ArtistImportIdentityRace::connect($this->schema);
    }

    public function close(): void
    {
        while ($this->writer->isTransactionActive()) {
            $this->writer->rollBack();
        }
        $this->manager->close();
        $this->observer->close();
        $this->writer->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        $this->writer->close();
    }

    /** @param array<string, string> $payload
     * @return array{process: resource, output: resource, application: string}
     */
    public function start(string $action, array $payload): array
    {
        $application = 'video_queue_' . bin2hex(random_bytes(6));
        $output = tmpfile();
        if ($output === false) {
            throw new RuntimeException('Cannot create the video queue contender output.');
        }
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/video-match-queue.php', $this->schema, $application, $action, json_encode($payload, JSON_THROW_ON_ERROR)],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        if (!is_resource($process)) {
            fclose($output);
            throw new RuntimeException('Cannot start the video queue contender.');
        }

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }

    /** @param array{process: resource, output: resource, application: string} $child */
    public function awaitRowWait(array $child): void
    {
        $deadline = microtime(true) + 10;
        do {
            $this->observer->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
            if ($this->observer->fetchOne(
                "SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock' AND wait_event = 'transactionid'",
                [$child['application']],
            ) !== false) {
                return;
            }
            if (!proc_get_status($child['process'])['running']) {
                break;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);
        rewind($child['output']);
        throw new RuntimeException('Video queue contender did not wait for its row: ' . stream_get_contents($child['output']));
    }
}
