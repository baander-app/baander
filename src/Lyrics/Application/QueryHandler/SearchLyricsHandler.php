<?php

declare(strict_types=1);

namespace App\Lyrics\Application\QueryHandler;

use App\Lyrics\Application\DTO\LrclibSearchResult;
use App\Lyrics\Application\DTO\LrclibUnavailable;
use App\Lyrics\Application\Exception\LyricsProviderUnavailableException;
use App\Lyrics\Application\Port\LrclibClientInterface;
use App\Lyrics\Application\Query\SearchLyricsQuery;
use App\Shared\Application\Exception\InvalidInputException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles SearchLyricsQuery: an empty list means LRCLIB has no match, an outage is reported.
 */
#[AsMessageHandler]
final readonly class SearchLyricsHandler
{
    public function __construct(
        private LrclibClientInterface $lrclibClient,
    ) {
    }

    /**
     * @return list<LrclibSearchResult>
     *
     * @throws InvalidInputException              when the query is blank
     * @throws LyricsProviderUnavailableException when LRCLIB did not answer
     */
    public function __invoke(SearchLyricsQuery $query): array
    {
        $keywords = trim($query->query);
        if ($keywords === '') {
            throw new InvalidInputException('Search query is required.');
        }

        $results = $this->lrclibClient->search($keywords);
        if ($results instanceof LrclibUnavailable) {
            throw new LyricsProviderUnavailableException();
        }

        return $results;
    }
}
