<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** An artist credit a song or album holds now. */
#[Exclude]
final readonly class CatalogCredit
{
    /**
     * @param Uuid        $artistId     internal: compare and look up by it, never show it
     * @param string|null $role         an ArtistRole value; null for a credit without a role
     * @param string      $creditedName the name the song or album is credited under, such as "Post Malone, Luke Combs"
     */
    public function __construct(
        public Uuid $artistId,
        public string $artistPublicId,
        public string $artistName,
        public ?string $role,
        public string $creditedName,
    ) {
    }

    /** Whether a match replaces the credit: matches credit primary and featured artists only. */
    public function isPerformerCredit(): bool
    {
        return $this->role === ArtistRole::Primary->value || $this->role === ArtistRole::Featured->value;
    }

    public function isPrimary(): bool
    {
        return $this->role === ArtistRole::Primary->value;
    }
}
