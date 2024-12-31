<?php

namespace App\Http\Controllers;

use App\Baander;
use MusicBrainz\Filter\PageFilter;
use MusicBrainz\Filter\Search\ReleaseFilter;
use MusicBrainz\MusicBrainz;
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

        $releaseFilter = new ReleaseFilter();
        $name = new Name('Teenage Dream: The Complete Confection');
        $releaseFilter->addReleaseName($name);

        $pageFilter = new PageFilter(0, 1);
        $artistList = $musicBrainz->api()->search()->release($releaseFilter, $pageFilter);
        $release = $artistList[0]->release;

        dd([
            'aliases'        => $release->getAliases(),
            'annotationText' => $release->getAnnotationText(),
            'artistCredit'   => $release->getArtistCredits()[0]->getArtist(),
            'country'        => $release->getCountry(),
            'date'           => $release->getDate(),
            'disambiguation' => $release->getDisambiguation(),
            'labelInfos'     => $release->getLabelInfos()->current(),
        ]);

        return view('dbg', [
            'release' => $release,
        ]);
    }
}