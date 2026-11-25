<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every page-numbered list refuses a page whose offset cannot be computed, before it reads.
 */
final class PageParameterTest extends TestCase
{
    /** @return array<string, array{string, bool}> path => [path, needs an administrator] */
    private static function routes(): array
    {
        $unknown = (new PublicId())->toString();

        return [
            'artists' => ['/api/artists/', false],
            'albums' => ['/api/albums/', false],
            'movies' => ['/api/movies/', false],
            'movie directors' => ['/api/movies/directors', false],
            'director movies' => ['/api/movies/directors/' . $unknown . '/movies', false],
            'people' => ['/api/people', false],
            'person credits' => ['/api/people/' . $unknown . '/credits', false],
            'movie credits' => ['/api/movies/' . $unknown . '/credits', false],
            'tv series' => ['/api/tv/series', false],
            'tv episodes' => ['/api/tv/series/' . $unknown . '/episodes', false],
            'tv series credits' => ['/api/tv/series/' . $unknown . '/credits', false],
            'tv episode credits' => ['/api/tv/episodes/' . $unknown . '/credits', false],
            'favorites' => ['/api/favorites/', false],
            'failed messages' => ['/api/monitor/transport/failed', true],
        ];
    }

    /** @return iterable<string, array{string, bool, string}> */
    public static function refusedPages(): iterable
    {
        $pages = [
            'offset overflow' => 'page=9223372036854775807&limit=100',
            'page 0' => 'page=0',
            'negative page' => 'page=-1',
            'non-numeric page' => 'page=abc',
        ];
        foreach (self::routes() as $route => [$path, $administrator]) {
            foreach ($pages as $case => $query) {
                yield $route . ', ' . $case => [$path, $administrator, $query];
            }
        }
    }

    #[DataProvider('refusedPages')]
    public function testAPageWithoutAComputableOffsetIsRefused(string $path, bool $administrator, string $query): void
    {
        $user = $administrator ? $this->createAdminUser() : $this->createTestUser();

        $response = $this->authenticatedRequest('GET', $path . '?' . $query, $user);

        $error = $this->assertJsonResponse($response, 400, 'error');
        self::assertArrayHasKey('page', $error['error']['details']);
    }

    /** @return iterable<string, array{string}> */
    public static function clampedLists(): iterable
    {
        yield 'people' => ['/api/people'];
        yield 'tv series' => ['/api/tv/series'];
    }

    #[DataProvider('clampedLists')]
    public function testThePeopleAndTvListsPageAsBefore(string $path): void
    {
        $user = $this->createTestUser();

        $first = $this->assertJsonResponse($this->authenticatedRequest('GET', $path, $user), 200, 'meta');
        self::assertSame(1, $first['meta']['current_page']);
        self::assertSame(50, $first['meta']['per_page']);

        $pastTheEnd = $this->assertJsonResponse($this->authenticatedRequest('GET', $path . '?page=1000000&limit=10', $user), 200, 'meta');
        self::assertSame(1000000, $pastTheEnd['meta']['current_page']);
        self::assertSame(10, $pastTheEnd['meta']['per_page']);
        self::assertSame([], $pastTheEnd['data']);
    }
}
