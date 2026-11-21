<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Tv;

use App\Metadata\Infrastructure\Tv\TvMetadataProvider;
use PHPUnit\Framework\TestCase;

final class TvMetadataProviderTest extends TestCase
{
    public function testAggregateCreditsPreserveEveryRoleAndJob(): void
    {
        $title = TvMetadataProvider::metadata([
            'id' => 123,
            'name' => 'A series',
            'aggregate_credits' => [
                'cast' => [
                    [
                        'id' => 5,
                        'name' => 'A performer',
                        'order' => 0,
                        'roles' => [
                            ['credit_id' => 'cast-first', 'character' => 'First character'],
                            ['credit_id' => 'cast-second', 'character' => 'Second character'],
                        ],
                    ],
                ],
                'crew' => [
                    [
                        'id' => 5,
                        'name' => 'A performer',
                        'department' => 'Directing',
                        'jobs' => [
                            ['credit_id' => 'director', 'job' => 'Director'],
                            ['credit_id' => 'producer', 'job' => 'Executive Producer'],
                        ],
                    ],
                ],
            ],
        ], 'aggregate_credits', true);

        self::assertNotNull($title);
        self::assertCount(2, $title->cast);
        self::assertCount(2, $title->crew);
        self::assertSame('5', $title->cast[0]->tmdbId);
        self::assertSame('First character', $title->cast[0]->characterName);
        self::assertSame('Second character', $title->cast[1]->characterName);
        self::assertSame(0, $title->cast[0]->billingOrder);
        self::assertSame('director', $title->crew[0]->role);
        self::assertSame('executive_producer', $title->crew[1]->role);
    }

    public function testEpisodeCreditsIncludeGuestsAndPreserveUnavailableCrew(): void
    {
        $title = TvMetadataProvider::metadata([
            'id' => 456,
            'name' => 'An episode',
            'air_date' => '2026-11-21',
            'credits' => [
                'cast' => [],
                'guest_stars' => [['id' => 8, 'name' => 'A guest', 'character' => 'A visitor']],
            ],
        ], 'credits', false);

        self::assertNotNull($title);
        self::assertCount(1, $title->cast);
        self::assertSame('A visitor', $title->cast[0]->characterName);
        self::assertNull($title->crew);
        self::assertSame('2026-11-21', $title->airDate);
    }

    public function testMalformedMemberMakesOnlyItsPartitionUnavailable(): void
    {
        $title = TvMetadataProvider::metadata([
            'id' => 456,
            'name' => 'An episode',
            'credits' => [
                'cast' => [['name' => 'Missing identity']],
                'crew' => [],
            ],
        ], 'credits', false);

        self::assertNotNull($title);
        self::assertNull($title->cast);
        self::assertSame([], $title->crew);
    }

    public function testAbsentGuestPartitionCannotClearStoredCast(): void
    {
        $title = TvMetadataProvider::metadata([
            'id' => 456,
            'name' => 'An episode',
            'credits' => ['cast' => [], 'crew' => []],
        ], 'credits', false);

        self::assertNotNull($title);
        self::assertNull($title->cast);
        self::assertSame([], $title->crew);
    }

    public function testMalformedGuestPartitionCannotClearStoredCast(): void
    {
        $title = TvMetadataProvider::metadata([
            'id' => 456,
            'name' => 'An episode',
            'credits' => [
                'cast' => [],
                'guest_stars' => [['name' => 'Missing identity']],
                'crew' => [],
            ],
        ], 'credits', false);

        self::assertNotNull($title);
        self::assertNull($title->cast);
    }

    public function testInvalidImageAndAirDateAreOmitted(): void
    {
        $title = TvMetadataProvider::metadata([
            'id' => 456,
            'name' => 'An episode',
            'still_path' => '//baander.app/unexpected',
            'air_date' => '2026-02-30',
        ], 'credits', false);

        self::assertNotNull($title);
        self::assertNull($title->imageUrl);
        self::assertNull($title->airDate);
        self::assertNull($title->cast);
        self::assertNull($title->crew);
    }

    public function testMissingTitleIdentityCannotRegisterACatalogRecord(): void
    {
        self::assertNull(TvMetadataProvider::metadata(['name' => 'Missing identity'], 'credits', false));
        self::assertNull(TvMetadataProvider::metadata(['id' => 1, 'name' => ' '], 'credits', false));
    }
}
