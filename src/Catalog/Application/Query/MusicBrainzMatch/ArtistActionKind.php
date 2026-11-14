<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

/** What a match does with a local artist besides crediting it. */
enum ArtistActionKind: string
{
    /** The artist moves its credits, favorites and play history to another artist and is removed. */
    case Merge = 'merge';
    /** The artist is removed with its favorites; no artist took over its primary credits. */
    case Remove = 'remove';
}
