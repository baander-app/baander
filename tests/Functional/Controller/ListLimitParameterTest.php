<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The song list and the recommendation lists refuse a limit outside their range instead
 * of clamping it, before they read.
 */
final class ListLimitParameterTest extends TestCase
{
    /** @return array<string, array{string, int}> route => [path, largest accepted limit] */
    private static function routes(): array
    {
        $unknown = new PublicId()->toString();

        return [
            'songs' => ['/api/songs/', 100],
            'recommendations' => ['/api/recommendations/', 200],
            'for you' => ['/api/recommendations/for-you', 100],
            'by source' => ['/api/recommendations/source/song/' . $unknown, 100],
            'targeting' => ['/api/recommendations/targeting/song/' . $unknown, 100],
        ];
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedLimits(): iterable
    {
        foreach (self::routes() as $route => [$path, $maximum]) {
            $limits = [
                'limit=0',
                'limit=' . ($maximum + 1),
                'limit=1.5',
                'limit=abc',
                'limit[]=1',
            ];
            foreach ($limits as $query) {
                yield $route . ', ' . $query => [$path, $query];
            }
        }
    }

    #[DataProvider('refusedLimits')]
    public function testALimitOutsideTheRangeIsRefused(string $path, string $query): void
    {
        $response = $this->authenticatedRequest('GET', $path . '?' . $query, $this->createTestUser());

        $error = $this->assertJsonResponse($response, 400, 'error');
        self::assertArrayHasKey('limit', $error['error']['details']);
    }

    /** @return iterable<string, array{string}> */
    public static function acceptedMaximums(): iterable
    {
        yield 'songs' => ['/api/songs/?limit=100'];
        yield 'recommendations' => ['/api/recommendations/?limit=200'];
        yield 'for you' => ['/api/recommendations/for-you?limit=100'];
    }

    #[DataProvider('acceptedMaximums')]
    public function testTheLargestLimitIsAccepted(string $uri): void
    {
        $response = $this->authenticatedRequest('GET', $uri, $this->createTestUser());

        $this->assertJsonResponse($response, 200, 'data');
    }
}
