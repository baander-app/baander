<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Shared\Domain\Model\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * What one resolution of artist identities decided: the artist of each identity, and the artists
 * it identified, which are the artists it created with a MusicBrainz ID and those that took on an
 * ID they did not hold. An artist that already held its ID is not identified again. Both describe
 * the resolving transaction and hold only once it commits.
 */
#[Exclude]
final readonly class ArtistResolution
{
    /**
     * @param array<string, Uuid>    $artistIds  the artist of each identity, keyed by {@see ArtistIdentity::key()}
     * @param list<IdentifiedArtist> $identified each artist the resolution identified, once
     */
    public function __construct(
        public array $artistIds,
        public array $identified = [],
    ) {
    }
}
