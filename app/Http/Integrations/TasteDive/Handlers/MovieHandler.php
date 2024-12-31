<?php

namespace App\Http\Integrations\TasteDive\Handlers;

use App\Http\Integrations\TasteDive\Handler;

class MovieHandler extends Handler
{
    public function getSimilarMovies(string $title)
    {
        return $this->fetchEndpoint('similar', [
            'q'    => $title,
            'type' => 'movie',
        ]);
    }

    public function getSimilarMusic(string $title)
    {
        return $this->fetchEndpoint('similar', [
            'q'    => $title,
            'type' => 'music',
        ]);
    }
}