<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

use App\Shared\Domain\Model\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** The local artist a MusicBrainz credit would resolve to if the match were applied now. */
#[Exclude]
final readonly class PredictedArtist
{
    /**
     * @param Uuid|null   $id       internal; null for an artist the match would create
     * @param string|null $publicId null for an artist the match would create
     * @param string      $name     the artist's name, or the name a new artist would get
     * @param bool        $takesId  whether a stored artist without a MusicBrainz ID would take on the credit's ID
     */
    public function __construct(
        public ?Uuid $id,
        public ?string $publicId,
        public string $name,
        public bool $takesId = false,
    ) {
    }

    public static function created(string $name): self
    {
        return new self(null, null, $name);
    }

    public function isNew(): bool
    {
        return $this->id === null;
    }
}
