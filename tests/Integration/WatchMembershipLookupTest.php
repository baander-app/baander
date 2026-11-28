<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Party\Infrastructure\Doctrine\Entity\PartyMemberEntity;
use App\Party\Infrastructure\Doctrine\Entity\SyncedPartySessionEntity;
use App\Party\Infrastructure\Doctrine\WatchMembershipLookup;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

final class WatchMembershipLookupTest extends TestCase
{
    private Connection $connection;
    private EntityManager $manager;
    private WatchMembershipLookup $lookup;
    private string $schema;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }
        $this->schema = 'watch_membership_' . bin2hex(random_bytes(8));
        $this->connection = ArtistImportIdentityRace::connect('public');
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach (['party_sessions', 'party_members'] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->connection, $table);
        }
        $this->manager = ArtistImportIdentityRace::entityManager($this->connection);
        $this->lookup = new WatchMembershipLookup($this->connection);
    }

    protected function tearDown(): void
    {
        if (!isset($this->connection)) {
            return;
        }
        $this->manager->clear();
        $this->connection->executeStatement('SET search_path TO public');
        $this->connection->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        $this->connection->close();
    }

    public function testOnlyTheHostAndMembersOfAnActivePartyForTheExactVideoAreWatching(): void
    {
        $host = Uuid::v7();
        $member = Uuid::v7();
        $video = Uuid::v7();
        $party = new SyncedPartySessionEntity(new PublicId(), $host, $video, null);
        $membership = new PartyMemberEntity(new PublicId(), $member, $party);
        $this->manager->persist($party);
        $this->manager->persist($membership);
        $this->manager->flush();
        self::assertTrue($this->lookup->isWatching($host, $video));
        self::assertTrue($this->lookup->isWatching($member, $video));
        self::assertFalse($this->lookup->isWatching(Uuid::v7(), $video));
        self::assertFalse($this->lookup->isWatching($host, Uuid::v7()));
        self::assertFalse($this->lookup->isWatching($member, Uuid::v7()));
        $this->connection->executeStatement('UPDATE party_sessions SET is_active = FALSE WHERE id = :id', ['id' => $party->getId()->toString()]);
        self::assertFalse($this->lookup->isWatching($host, $video));
        self::assertFalse($this->lookup->isWatching($member, $video));
    }

    public function testLeavingAPartyIsObservedWithoutReusingAManagedMembership(): void
    {
        $host = Uuid::v7();
        $member = Uuid::v7();
        $video = Uuid::v7();
        $party = new SyncedPartySessionEntity(new PublicId(), $host, $video, null);
        $membership = new PartyMemberEntity(new PublicId(), $member, $party);
        $this->manager->persist($party);
        $this->manager->persist($membership);
        $this->manager->flush();
        self::assertTrue($this->lookup->isWatching($member, $video));
        $this->connection->executeStatement('DELETE FROM party_members WHERE id = :id', ['id' => $membership->getId()->toString()]);
        self::assertFalse($this->lookup->isWatching($member, $video));
        self::assertTrue($this->lookup->isWatching($host, $video));
    }
}
