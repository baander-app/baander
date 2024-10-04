<?php

namespace App\Http\Controllers;

use App\Extensions\BaseBuilder;
use App\Http\Integrations\Github\BaanderGhApi;
use App\Http\Resources\Album\AlbumResource;
use App\Models\Album;
use App\Packages\PhpInfoParser\Info;
use Illuminate\Http\Request;

class UIController
{
    public function getUI()
    {
        return view('app', [
            'appInfo' => [
                'name'        => config('app.name'),
                'environment' => config('app.env'),
                'debug'       => config('app.debug'),
                'locale'      => config('app.locale'),
            ],
        ]);
    }

    public function dbg()
    {
        $relations = implode(',', Album::$filterRelations);

        $albums = Album::query()
            ->withRelations(Album::$filterRelations, $relations)
            ->when($relations, function (BaseBuilder $q) use ($relations) {
                return $q->with(explode(',', $relations));
            })->with('songs.genres')
            ->paginate();

        dd((new AlbumResource($albums->items()[1]))->toArray(request()));

        return view('dbg', [
        ]);
    }
}