<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;

final class RadioStationControllerTest extends TestCase
{
    public function testTheStationListPagesByNameWithTheTotal(): void
    {
        $country = self::country();
        $this->insertNumberedStations($country, 501);
        $user = $this->createTestUser();

        $first = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/radio/stations?country=' . $country . '&limit=500', $user), 200, 'meta');
        self::assertCount(500, $first['data']);
        self::assertSame('Station 001', $first['data'][0]['name']);
        self::assertSame(['total' => 501, 'limit' => 500, 'offset' => 0], $first['meta']);

        $last = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/radio/stations?country=' . $country . '&limit=500&offset=500', $user), 200, 'meta');
        self::assertSame(['Station 501'], array_column($last['data'], 'name'));
        self::assertSame(501, $last['meta']['total']);
    }

    public function testALimitOverFiveHundredIsRefused(): void
    {
        $response = $this->authenticatedRequest('GET', '/api/radio/stations?limit=501', $this->createTestUser());

        $error = $this->assertJsonResponse($response, 400, 'error');
        self::assertArrayHasKey('limit', $error['error']['details']);
    }

    public function testASearchMatchesPercentAndUnderscoreLiterally(): void
    {
        $country = self::country();
        $this->insertStations($country, ['Radio 50% Off', 'Radio 500', 'Radio_X', 'RadioAX']);
        $user = $this->createTestUser();

        $percent = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/radio/stations?country=' . $country . '&q=' . rawurlencode('50%'), $user), 200, 'data');
        self::assertSame(['Radio 50% Off'], array_column($percent['data'], 'name'));

        $underscore = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/radio/stations?country=' . $country . '&q=o_x', $user), 200, 'data');
        self::assertSame(['Radio_X'], array_column($underscore['data'], 'name'));
    }

    public function testStationsAreReadByTheirPublicIds(): void
    {
        $country = self::country();
        [$first, , $third] = $this->insertStations($country, ['First', 'Second', 'Third']);
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/radio/stations?publicIds=' . $third . ',' . $first, $user);

        $page = $this->assertJsonResponse($response, 200, 'meta');
        self::assertSame(['First', 'Third'], array_column($page['data'], 'name'));
        self::assertSame(2, $page['meta']['total']);
    }

    public function testMalformedOrTooManyPublicIdsAreRefused(): void
    {
        $user = $this->createTestUser();
        $tooMany = implode(',', array_map(static fn (): string => (new PublicId())->toString(), range(1, 101)));

        foreach (['not-a-public-id', $tooMany] as $publicIds) {
            $error = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/radio/stations?publicIds=' . $publicIds, $user), 400, 'error');
            self::assertArrayHasKey('publicIds', $error['error']['details']);
        }
    }

    /** A country code no other test uses, so each test counts only its own stations. */
    private static function country(): string
    {
        return 'T' . strtoupper(bin2hex(random_bytes(3)));
    }

    private function insertNumberedStations(string $country, int $count): void
    {
        $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO radio_stations (id, public_id, source_id, external_id, name, country, created_at, updated_at)
                SELECT gen_random_uuid(), ? || lpad(i::text, 15, '0'), ?, 'numbered-' || i, 'Station ' || lpad(i::text, 3, '0'), ?, now(), now()
                FROM generate_series(1, ?) AS i
                SQL,
            [bin2hex(random_bytes(3)), $this->source(), $country, $count],
        );
    }

    /**
     * @param list<string> $names
     *
     * @return list<string> the stations' public ids, in the order of $names
     */
    private function insertStations(string $country, array $names): array
    {
        $connection = $this->entityManager->getConnection();
        $source = $this->source();
        $publicIds = [];
        foreach ($names as $name) {
            $publicId = (new PublicId())->toString();
            $connection->executeStatement(
                'INSERT INTO radio_stations (id, public_id, source_id, external_id, name, country, created_at, updated_at) VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, now(), now())',
                [$publicId, $source, 'named-' . $publicId, $name, $country],
            );
            $publicIds[] = $publicId;
        }

        return $publicIds;
    }

    private function source(): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne(
            "INSERT INTO radio_sources (id, public_id, name, type, sync_url, created_at, updated_at) VALUES (gen_random_uuid(), ?, 'Test source', 'iprd', 'https://radio.baander.app/catalog', now(), now()) RETURNING id",
            [(new PublicId())->toString()],
        );
    }
}
