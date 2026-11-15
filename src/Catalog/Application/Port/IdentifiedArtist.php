<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Shared\Domain\Model\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** An artist a writer created with a MusicBrainz ID, or gave the MusicBrainz ID it holds now. */
#[Exclude]
final readonly class IdentifiedArtist
{
    /** @param string $musicbrainzId the MusicBrainz artist ID the artist holds, in canonical form */
    public function __construct(
        public Uuid $artistId,
        public string $musicbrainzId,
    ) {
    }
}
