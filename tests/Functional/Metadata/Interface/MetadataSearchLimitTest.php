<?php

declare(strict_types=1);

namespace App\Tests\Functional\Metadata\Interface;

use App\Auth\Domain\Model\User;
use App\Tests\Functional\Auth\RateLimitTestCase;

/**
 * The metadata search routes bound the result count they ask a provider for. Each test spends
 * the member's Discogs and Last.fm allowance first, so no request reaches either provider: a
 * count outside 1 to 100 answers 422 before the allowance is read, and an accepted count
 * reaches the spent allowance and answers 429.
 */
final class MetadataSearchLimitTest extends RateLimitTestCase
{
    public function testAResultCountOutsideOneToAHundredAnswersUnprocessable(): void
    {
        $member = $this->memberWithSpentProviderAllowance();

        foreach (['100000', '0'] as $limit) {
            $response = $this->authenticatedRequest(
                'GET',
                '/api/metadata/search/artist?q=Radiohead&source=lastfm&limit=' . $limit,
                $member,
            );

            $this->assertJsonResponse($response, 422);
        }
    }

    public function testAHundredResultsIsAcceptedAndTheProviderAllowanceApplies(): void
    {
        $member = $this->memberWithSpentProviderAllowance();

        $this->assertTooManyRequests($this->authenticatedRequest(
            'GET',
            '/api/metadata/search/artist?q=Radiohead&source=lastfm&limit=100',
            $member,
        ));
        $this->assertTooManyRequests($this->authenticatedRequest(
            'GET',
            '/api/metadata/search/album?q=OK%20Computer&source=discogs&limit=100',
            $member,
        ));
    }

    private function memberWithSpentProviderAllowance(): User
    {
        $member = $this->createTestUser();
        $this->exhaust('metadata_provider_user', $member->getId()->toString());

        return $member;
    }
}
