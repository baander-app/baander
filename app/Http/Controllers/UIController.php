<?php

namespace App\Http\Controllers;

use App\Baander;
use App\Models\Song;
use JetBrains\PhpStorm\NoReturn;

class UIController
{
    public function getUI()
    {
        return view('app', [
            'appInfo' => Baander::getAppInfo(),
        ]);
    }

    #[NoReturn] public function dbg()
    {
        $data = (new \App\Models\Song)->find(1);

        $rec = $data->getRecommendations('same_genre');

        dd([
            'song' => $data->toArray(),
            'recom' => $rec->each(fn($a) => $a->get('title'))->toArray(),
        ]);
    }
}