<?php

declare(strict_types=1);

namespace App\Tests\Functional\Lyrics;

use App\Lyrics\Application\DTO\LrclibResult;
use App\Lyrics\Application\DTO\LrclibSearchResult;
use App\Lyrics\Application\DTO\LrclibUnavailable;
use App\Lyrics\Application\Port\LrclibClientInterface;

/** Answers every LRCLIB call with what the test set, so no request leaves the test. */
final class FakeLrclibClient implements LrclibClientInterface
{
    public LrclibResult|LrclibUnavailable|null $signatureAnswer = null;
    public LrclibResult|LrclibUnavailable|null $byIdAnswer = null;
    /** @var list<LrclibSearchResult>|LrclibUnavailable */
    public array|LrclibUnavailable $searchAnswer = [];
    /** @var list<string> the methods called, in order */
    public array $calls = [];

    public function unavailable(): void
    {
        $outage = new LrclibUnavailable('HTTP 503');
        $this->signatureAnswer = $outage;
        $this->byIdAnswer = $outage;
        $this->searchAnswer = $outage;
    }

    public function getBySignatureCached(string $trackName, string $artistName, string $albumName, float $duration): LrclibResult|LrclibUnavailable|null
    {
        $this->calls[] = 'getBySignatureCached';

        return $this->signatureAnswer;
    }

    public function getBySignature(string $trackName, string $artistName, string $albumName, float $duration): LrclibResult|LrclibUnavailable|null
    {
        $this->calls[] = 'getBySignature';

        return $this->signatureAnswer;
    }

    public function getById(int $id): LrclibResult|LrclibUnavailable|null
    {
        $this->calls[] = 'getById';

        return $this->byIdAnswer instanceof LrclibResult && $this->byIdAnswer->id !== $id ? null : $this->byIdAnswer;
    }

    public function search(string $query): array|LrclibUnavailable
    {
        $this->calls[] = 'search';

        return $this->searchAnswer;
    }
}
