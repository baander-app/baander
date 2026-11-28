<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\MusicBrainz;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** A lookup skipped because the administrator switched off its provider. */
#[Exclude]
final readonly class MusicBrainzDisabled
{
    public function __construct(public string $provider = 'musicbrainz') {}
}
