<?php

namespace App\Http\Controllers;

use App\Baander;
use App\Models\Song;

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
        $data = Song::find(1);

        $rec = $data->getRecommendations('same_genre');

        dd([
            'song' => $data->toArray(),
            'recom' => $rec->each(fn($a) => $a->get('title'))->toArray(),
        ]);
    }
}