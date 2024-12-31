<?php

namespace App\Http\Integrations\MusicBrainz\Subqueries;

use App\Http\Integrations\MusicBrainz\Handler;

class ArtistAliasHandler extends Handler
{
    public function list(string $artistMbid): ?array
    {
        return $this->fetchEndpoint('artist/' . $artistMbid . '/aliases');
    }
}