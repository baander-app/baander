<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Radio\Domain\Model\CountrySubscription\CountrySubscription;
use App\Radio\Domain\Model\CountrySubscription\CountrySubscriptionState;
use App\Radio\Domain\Model\RadioSession\RadioSession;
use App\Radio\Domain\Model\StarredStation\StarredStation;
use App\Radio\Domain\Model\StarredStation\StarredStationState;
use App\Radio\Infrastructure\Doctrine\Entity\CountrySubscriptionEntity;
use App\Radio\Infrastructure\Doctrine\Entity\RadioSessionEntity;
use App\Radio\Infrastructure\Doctrine\Entity\RadioSourceEntity;
use App\Radio\Infrastructure\Doctrine\Entity\RadioStationEntity;
use App\Radio\Infrastructure\Doctrine\Entity\StarredStationEntity;
use App\Radio\Infrastructure\Doctrine\Repository\CountrySubscriptionDoctrineRepository;
use App\Radio\Infrastructure\Doctrine\Repository\RadioSessionDoctrineRepository;
use App\Radio\Infrastructure\Doctrine\Repository\StarredStationDoctrineRepository;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RadioOwnershipPersistenceTest extends TestCase
{
    use OwnershipPersistenceHarness;

    /** @var array<string, array{class-string, string}> table => [entity, migration-defined FK name] */
    private const TABLES = [
        'country_subscriptions' => [CountrySubscriptionEntity::class, 'fk_country_subscriptions_user_id'],
        'radio_sessions' => [RadioSessionEntity::class, 'fk_radio_sessions_user_id'],
        'starred_stations' => [StarredStationEntity::class, 'fk_starred_stations_user_id'],
    ];

    public function testOwnersAreScalarUuidFields(): void
    {
        $owners = [];
        foreach (self::TABLES as [$entity]) {
            $owners[$entity] = ['userId', 'user_id'];
        }

        $this->assertScalarUuidOwners($owners);
    }

    public function testDeclaredForeignKeysMatchTheCatalog(): void
    {
        $this->assertDeclaredForeignKeysMatchCatalog(array_map(
            static fn (array $table): array => ['user_id', $table[1]],
            self::TABLES,
        ));
    }

    public function testSchemaComparisonIsCleanForRadioTables(): void
    {
        $this->assertSchemaComparisonIsClean(array_keys(self::TABLES));
    }

    public function testFindersReturnOnlyEachOwnersRowsFromInterleavedData(): void
    {
        $first = $this->createUser();
        $second = $this->createUser();
        [$source, $one, $two] = $this->createStations();
        $subscriptions = new CountrySubscriptionDoctrineRepository($this->manager);
        $starred = new StarredStationDoctrineRepository($this->manager);
        $sessions = new RadioSessionDoctrineRepository($this->manager);

        $ids = [];
        foreach ([[$first, 'DK'], [$second, 'DK'], [$first, 'SE']] as [$owner, $country]) {
            $subscription = CountrySubscription::create($owner, $source, $country);
            $subscriptions->save($subscription);
            $ids['subscription'][$owner->toString()][$country] = $subscription->getId();
        }
        foreach ([[$first, $one], [$second, $one], [$first, $two]] as [$owner, $station]) {
            $star = StarredStation::create($owner, $station);
            $starred->save($star);
            $ids['star'][$owner->toString()][$station->toString()] = $star->getId();
        }
        foreach ([$first, $second] as $owner) {
            $session = RadioSession::create($owner);
            $session->start($owner === $first ? $one : $two, 'https://radio.baander.app/' . $owner->toString());
            $sessions->save($session);
            $ids['session'][$owner->toString()] = $session->getId();
        }
        $this->manager->clear();

        foreach ([$first, $second] as $owner) {
            $key = $owner->toString();
            self::assertSame(
                self::sortedIds(...array_values($ids['subscription'][$key])),
                self::sortedIds(...array_map(static fn (CountrySubscription $s): Uuid => $s->getId(), $subscriptions->findByUserId($owner))),
            );
            foreach ($ids['subscription'][$key] as $country => $id) {
                $found = $subscriptions->findByUserAndSourceAndCountry($owner, $source, $country);
                self::assertNotNull($found);
                self::assertTrue($found->getId()->equals($id));
                self::assertTrue($found->getUserId()->equals($owner));
            }

            self::assertSame(
                self::sortedIds(...array_values($ids['star'][$key])),
                self::sortedIds(...array_map(static fn (StarredStation $s): Uuid => $s->getId(), $starred->findByUserId($owner))),
            );
            foreach ($ids['star'][$key] as $id) {
                $star = $starred->find($id);
                self::assertNotNull($star);
                $found = $starred->findByUserIdAndStationId($owner, $star->getStationId());
                self::assertNotNull($found);
                self::assertTrue($found->getId()->equals($id));
                self::assertTrue($found->getUserId()->equals($owner));
            }

            $session = $sessions->findByUserId($owner);
            self::assertNotNull($session);
            self::assertTrue($session->getId()->equals($ids['session'][$key]));
            self::assertTrue($session->getUserId()->equals($owner));
            self::assertSame('playing', $session->getState());
        }

        self::assertNull($subscriptions->findByUserAndSourceAndCountry($second, $source, 'SE'));
        self::assertNull($starred->findByUserIdAndStationId($second, $two));
    }

    public function testUserDeletionCascadesOnlyThatUsersRows(): void
    {
        $first = $this->createUser();
        $second = $this->createUser();
        [$source, $station] = $this->createStations();
        foreach ([$first, $second] as $owner) {
            (new CountrySubscriptionDoctrineRepository($this->manager))->save(CountrySubscription::create($owner, $source, 'DK'));
            (new StarredStationDoctrineRepository($this->manager))->save(StarredStation::create($owner, $station));
            (new RadioSessionDoctrineRepository($this->manager))->save(RadioSession::create($owner));
        }
        $this->manager->clear();

        $this->deleteUser($first);

        foreach (array_keys(self::TABLES) as $table) {
            self::assertSame(0, $this->countOwnedRows($table, 'user_id', $first), $table);
            self::assertSame(1, $this->countOwnedRows($table, 'user_id', $second), $table);
        }
    }

    public function testNewRowsPersistTheAggregatesTimestamps(): void
    {
        $owner = $this->createUser();
        [$source, $station] = $this->createStations();
        $starredAt = new \DateTimeImmutable('2024-02-29 13:14:15');
        $createdAt = new \DateTimeImmutable('2023-11-05 01:02:03');
        $star = StarredStation::reconstitute(new StarredStationState(Uuid::generate(), $owner, $station, $starredAt));
        $subscription = CountrySubscription::reconstitute(new CountrySubscriptionState(Uuid::generate(), $owner, $source, 'DK', null, $createdAt));

        (new StarredStationDoctrineRepository($this->manager))->save($star);
        (new CountrySubscriptionDoctrineRepository($this->manager))->save($subscription);
        $this->manager->clear();

        $loadedStar = (new StarredStationDoctrineRepository($this->manager))->find($star->getId());
        $loadedSubscription = (new CountrySubscriptionDoctrineRepository($this->manager))->find($subscription->getId());
        self::assertNotNull($loadedStar);
        self::assertNotNull($loadedSubscription);
        self::assertSame($starredAt->getTimestamp(), $loadedStar->getStarredAt()->getTimestamp());
        self::assertSame($createdAt->getTimestamp(), $loadedSubscription->getCreatedAt()->getTimestamp());
    }

    /** @return iterable<string, array{string}> */
    public static function tables(): iterable
    {
        foreach (array_keys(self::TABLES) as $table) {
            yield $table => [$table];
        }
    }

    /** @param key-of<self::TABLES> $table */
    #[DataProvider('tables')]
    public function testForeignKeyRejectsAnUnknownOwner(string $table): void
    {
        [$source, $station] = $this->createStations();
        $unknown = Uuid::generate();

        $this->expectException(ForeignKeyConstraintViolationException::class);

        match ($table) {
            'country_subscriptions' => (new CountrySubscriptionDoctrineRepository($this->manager))
                ->save(CountrySubscription::create($unknown, $source, 'DK')),
            'starred_stations' => (new StarredStationDoctrineRepository($this->manager))
                ->save(StarredStation::create($unknown, $station)),
            'radio_sessions' => (new RadioSessionDoctrineRepository($this->manager))
                ->save(RadioSession::create($unknown)),
        };
    }

    /** @return list<string> */
    private static function sortedIds(Uuid ...$ids): array
    {
        $values = array_map(static fn (Uuid $id): string => $id->toString(), $ids);
        sort($values);

        return $values;
    }

    /** @return array{Uuid, Uuid, Uuid} source and two stations */
    private function createStations(): array
    {
        $source = new RadioSourceEntity(Uuid::generate(), 'Ownership source', 'radio-browser', 'https://radio.baander.app');
        $this->manager->persist($source);
        $stations = [];
        foreach (['one', 'two'] as $name) {
            $station = new RadioStationEntity(Uuid::generate(), $source, 'ownership-' . $name . '-' . $source->getId()->toString(), 'Station ' . $name, 'DK');
            $this->manager->persist($station);
            $stations[] = $station->getId();
        }
        $this->manager->flush();

        return [$source->getId(), ...$stations];
    }
}
