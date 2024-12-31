<?php

namespace App\Http\Controllers;

use App\Baander;
use MusicBrainz\Filter\PageFilter;
use MusicBrainz\Filter\Search\RecordingFilter;
use MusicBrainz\Filter\Search\ReleaseFilter;
use MusicBrainz\MusicBrainz;
use MusicBrainz\Supplement\Lookup\RecordingFields;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\Name;

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
        $musicBrainz = app(MusicBrainz::class);

        $filter = new RecordingFilter();
        $filter->addRecordingNameWithoutAccents(new Name('Teenage dream'));
        $filter->addArtistNameWithoutAccents(new Name('Katy Perry'));

        $pageFilter = new PageFilter(0, 1);

        $recording = $musicBrainz->api()->search()->recording($filter, $pageFilter);

        $mbid = $recording[0]->recording->getMBID();

        $fields = (new RecordingFields)
            ->includeReleaseRelations()
            ->includeRecordingRelations()
            ->includeArtistRelations();

        $res = $musicBrainz->api()->lookup()->recording(new MBID($mbid), $fields);

        dd($res);

        return view('dbg', [
            'release' => $release,
        ]);
    }
}