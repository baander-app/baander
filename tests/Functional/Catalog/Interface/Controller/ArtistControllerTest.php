<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Interface\Controller;

use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;

final class ArtistControllerTest extends TestCase
{
    /** No artist has a malformed public ID, so a lookup by one answers as an unknown ID does. */
    public function testAMalformedArtistIdIsNotFoundLikeAnUnknownOne(): void
    {
        $user = $this->createTestUser();

        $malformed = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/artists/not-an-id', $user), 404, 'error');
        $unknown = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/artists/' . new PublicId(), $user), 404, 'error');

        self::assertSame($unknown, $malformed);
    }
}
