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
}
