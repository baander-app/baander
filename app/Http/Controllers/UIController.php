<?php

namespace App\Http\Controllers;

use App\Baander;
use App\Models\Album;
use App\Modules\MediaMeta\MediaMeta;

class UIController
{
    public function getUI()
    {
        return view('app', [
            'appInfo' => Baander::getAppInfo(),
        ]);
    }

    public function dbg()
    {
        $album = Album::first();
        $song = $album->songs()->first();

        $meta = new MediaMeta($song->path);

        dd($meta->getArtist());
    }
}