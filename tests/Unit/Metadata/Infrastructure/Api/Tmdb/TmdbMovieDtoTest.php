<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Api\Tmdb;

use App\Metadata\Infrastructure\Api\Tmdb\DTO\TmdbMovieDto;
use PHPUnit\Framework\TestCase;

final class TmdbMovieDtoTest extends TestCase
{
    public function testDirectorIdentityAndAuthoritativeEmptyCreditsArePreserved(): void
    {
        $movie = TmdbMovieDto::fromApiResponse([
            'id' => 1,
            'credits' => ['crew' => [
                ['id' => 41, 'name' => 'Director One', 'job' => 'Director', 'profile_path' => '/one.jpg'],
                ['id' => 42, 'name' => 'Director Two', 'job' => 'Director'],
                ['id' => 43, 'name' => 'Writer', 'job' => 'Writer'],
            ]],
        ]);

        self::assertTrue($movie->creditsAvailable);
        self::assertSame([41, 42], array_column($movie->crew, 'id'));
        self::assertSame('/one.jpg', $movie->crew[0]['profilePath']);
        self::assertTrue(TmdbMovieDto::fromApiResponse(['id' => 1, 'credits' => ['crew' => []]])->creditsAvailable);
        self::assertFalse(TmdbMovieDto::fromApiResponse(['id' => 1])->creditsAvailable);
    }
    public function testFullCreditsKeepCastBeyondTheSummaryAndEveryCrewJob(): void
    {
        $cast = [];
        for ($index = 1; $index <= 12; ++$index) {
            $cast[] = ['id' => $index, 'name' => 'Actor ' . $index, 'character' => 'Character ' . $index, 'order' => $index - 1, 'credit_id' => 'cast-' . $index];
        }
        $movie = TmdbMovieDto::fromApiResponse([
            'id' => 1,
            'credits' => [
                'cast' => $cast,
                'crew' => [
                    ['id' => 1, 'name' => 'Actor 1', 'job' => 'Director', 'credit_id' => 'director-1'],
                    ['id' => 1, 'name' => 'Actor 1', 'job' => 'Executive Producer', 'credit_id' => 'producer-1'],
                ],
            ],
        ]);

        self::assertCount(10, $movie->cast);
        self::assertCount(12, $movie->fullCast);
        self::assertCount(2, $movie->fullCrew);
        self::assertSame('actor', $movie->fullCast[11]->role);
        self::assertSame('Character 12', $movie->fullCast[11]->characterName);
        self::assertSame('executive_producer', $movie->fullCrew[1]->role);
        self::assertSame('producer-1', $movie->fullCrew[1]->providerCreditId);
    }

    public function testMalformedPartitionsRemainUnavailableIndependentlyOfAuthoritativeEmpty(): void
    {
        $movie = TmdbMovieDto::fromApiResponse([
            'id' => 1,
            'credits' => ['cast' => [], 'crew' => [['name' => 'Missing identity', 'job' => 'Writer']]],
        ]);

        self::assertSame([], $movie->fullCast);
        self::assertNull($movie->fullCrew);
        self::assertFalse($movie->creditsAvailable);
    }

}
