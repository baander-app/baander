<?php

declare(strict_types=1);

namespace App\Tests\Functional\Lyrics;

use App\Lyrics\Application\DTO\LrclibRejected;
use App\Lyrics\Application\DTO\LrclibResult;
use App\Lyrics\Application\DTO\LrclibSearchResult;
use App\Lyrics\Application\DTO\LrclibUnavailable;
use App\Lyrics\Application\Port\LrclibClientInterface;

/** Answers every LRCLIB call with what the test set, so no request leaves the test. */
final class FakeLrclibClient implements LrclibClientInterface
{
    public LrclibResult|LrclibRejected|LrclibUnavailable|null $signatureAnswer = null;
    public LrclibResult|LrclibRejected|LrclibUnavailable|null $byIdAnswer = null;
    /** @var list<LrclibSearchResult>|LrclibRejected|LrclibUnavailable */
    public array|LrclibRejected|LrclibUnavailable $searchAnswer = [];
    /** @var list<string> the methods called, in order */
    public array $calls = [];
    /** When set, every call goes to this client instead of the answers above. */
    public ?LrclibClientInterface $delegate = null;

    public function unavailable(): void
    {
        $outage = new LrclibUnavailable('HTTP 503');
        $this->signatureAnswer = $outage;
        $this->byIdAnswer = $outage;
        $this->searchAnswer = $outage;
    }

    /** Every call is refused as LRCLIB refuses a duration of 0. */
    public function rejecting(): void
    {
        $rejection = new LrclibRejected(400);
        $this->signatureAnswer = $rejection;
        $this->byIdAnswer = $rejection;
        $this->searchAnswer = $rejection;
    }

    public function getBySignatureCached(string $trackName, string $artistName, string $albumName, float $duration): LrclibResult|LrclibRejected|LrclibUnavailable|null
    {
        $this->calls[] = 'getBySignatureCached';

        return $this->delegate !== null ? $this->delegate->getBySignatureCached($trackName, $artistName, $albumName, $duration) : $this->signatureAnswer;
    }

    public function getBySignature(string $trackName, string $artistName, string $albumName, float $duration): LrclibResult|LrclibRejected|LrclibUnavailable|null
    {
        $this->calls[] = 'getBySignature';

        return $this->delegate !== null ? $this->delegate->getBySignature($trackName, $artistName, $albumName, $duration) : $this->signatureAnswer;
    }

    public function getById(int $id): LrclibResult|LrclibRejected|LrclibUnavailable|null
    {
        $this->calls[] = 'getById';
        if ($this->delegate !== null) {
            return $this->delegate->getById($id);
        }

        return $this->byIdAnswer instanceof LrclibResult && $this->byIdAnswer->id !== $id ? null : $this->byIdAnswer;
    }

    public function search(string $query): array|LrclibRejected|LrclibUnavailable
    {
        $this->calls[] = 'search';

        return $this->delegate !== null ? $this->delegate->search($query) : $this->searchAnswer;
    }
}
