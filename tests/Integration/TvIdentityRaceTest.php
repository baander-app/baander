<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use App\Tests\Fixtures\Catalog\TvIdentityRace;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use PHPUnit\Framework\TestCase;

#[SkipDatabaseRollback]
final class TvIdentityRaceTest extends TestCase
{
    private TvIdentityRace $fixture;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }
        $this->fixture = new TvIdentityRace();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->close();
        }
        parent::tearDown();
    }

    public function testTwoSeasonImportsOfANewSeriesWaitAndCreateOneSeries(): void
    {
        $first = $this->fixture->video('Show/Season 01/Show.S01E01.mp4');
        $second = $this->fixture->video('Show/Season 02/Show.S02E01.mp4', 'blue');
        $connection = $this->fixture->connection;
        $connection->beginTransaction();
        $child = null;
        $result = null;
        try {
            $this->fixture->ingest($first);
            self::assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM tv_series'));
            self::assertSame(0, (int) $this->fixture->observer->fetchOne('SELECT count(*) FROM tv_series'));
            $child = $this->fixture->start($this->fixture->message($second));
            ArtistImportIdentityRace::awaitAdvisoryLockWait($this->fixture->observer, $child);
            $connection->commit();
        } finally {
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            if ($child !== null) {
                $result = ArtistImportIdentityRace::finish($child, $this->fixture->observer);
            }
        }

        self::assertSame(['series' => 1, 'seasons' => 2, 'episodes' => 2], $result);
        self::assertSame(2, (int) $this->fixture->observer->fetchOne('SELECT count(*) FROM video_placements'));
    }
}
