<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Interface\Controller;

use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AlbumControllerTest extends TestCase
{
    /** No album has a malformed public ID, so a lookup by one answers as an unknown ID does. */
    #[DataProvider('albumLookups')]
    public function testAMalformedAlbumIdIsNotFoundLikeAnUnknownOne(string $suffix): void
    {
        $user = $this->createTestUser();

        $malformed = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/albums/not-an-id' . $suffix, $user), 404, 'error');
        $unknown = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/albums/' . new PublicId() . $suffix, $user), 404, 'error');

        self::assertSame($unknown, $malformed);
    }

    /** @return iterable<string, array{string}> */
    public static function albumLookups(): iterable
    {
        yield 'album' => [''];
        yield 'cover' => ['/cover'];
        yield 'duplicates' => ['/duplicates'];
    }
}
