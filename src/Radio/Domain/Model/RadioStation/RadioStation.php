<?php

declare(strict_types=1);

namespace App\Radio\Domain\Model\RadioStation;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class RadioStation
{
    private array $pendingEvents = [];

    private function __construct(
        private RadioStationState $state,
    ) {
    }

    /**
     * @param Uuid $sourceId
     * @param string $externalId
     * @param string $name
     * @param string $country
     * @param list<Stream> $streams
     * @param string|null $language
     * @param list<string> $genres
     * @param list<string> $tags
     * @param string|null $logo
     * @param string|null $website
     */
    public static function create(
        Uuid $sourceId,
        string $externalId,
        string $name,
        string $country,
        array $streams,
        ?string $language = null,
        array $genres = [],
        array $tags = [],
        ?string $logo = null,
        ?string $website = null,
    ): self {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Radio station name cannot be empty.');
        }

        if (trim($country) === '') {
            throw new InvalidArgumentException('Radio station country cannot be empty.');
        }

        $now = new DateTimeImmutable();

        return new self(new RadioStationState(
            id: new Uuid(),
            sourceId: $sourceId,
            externalId: $externalId,
            name: $name,
            country: $country,
            language: $language,
            genres: $genres,
            tags: $tags,
            streams: $streams,
            logo: $logo,
            website: $website,
            lastCheckedAt: null,
            createdAt: $now,
            updatedAt: $now,
        ));
    }

    public static function reconstitute(RadioStationState $state): self
    {
        return new self($state);
    }

    /**
     * @param list<Stream>|null $streams
     * @param list<string>|null $genres
     * @param list<string>|null $tags
     */
    public function updateDetails(
        ?string $name = null,
        ?array $streams = null,
        ?array $genres = null,
        ?array $tags = null,
        ?string $language = null,
        ?string $logo = null,
        ?string $website = null,
        ?DateTimeImmutable $lastCheckedAt = null,
    ): void {
        if ($name !== null) {
            if (trim($name) === '') {
                throw new InvalidArgumentException('Radio station name cannot be empty.');
            }
            $this->state->name = $name;
        }

        if ($streams !== null) {
            $this->state->streams = $streams;
        }

        if ($genres !== null) {
            $this->state->genres = $genres;
        }

        if ($tags !== null) {
            $this->state->tags = $tags;
        }

        if ($language !== null) {
            $this->state->language = $language;
        }

        if ($logo !== null) {
            $this->state->logo = $logo;
        }

        if ($website !== null) {
            $this->state->website = $website;
        }

        if ($lastCheckedAt !== null) {
            $this->state->lastCheckedAt = $lastCheckedAt;
        }

        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * @return list<object>
     */
    public function drainPendingEvents(): array
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];

        return $events;
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getSourceId(): Uuid
    {
        return $this->state->sourceId;
    }

    public function getExternalId(): string
    {
        return $this->state->externalId;
    }

    public function getName(): string
    {
        return $this->state->name;
    }

    public function getCountry(): string
    {
        return $this->state->country;
    }

    public function getLanguage(): ?string
    {
        return $this->state->language;
    }

    /**
     * @return list<string>
     */
    public function getGenres(): array
    {
        return $this->state->genres;
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return $this->state->tags;
    }

    /**
     * @return list<Stream>
     */
    public function getStreams(): array
    {
        return $this->state->streams;
    }

    public function getLogo(): ?string
    {
        return $this->state->logo;
    }

    public function getWebsite(): ?string
    {
        return $this->state->website;
    }

    public function getLastCheckedAt(): ?DateTimeImmutable
    {
        return $this->state->lastCheckedAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getState(): RadioStationState
    {
        return $this->state;
    }
}
