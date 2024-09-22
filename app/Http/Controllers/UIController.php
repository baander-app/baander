<?php

namespace App\Http\Controllers;

use App\Http\Integrations\Github\BaanderGhApi;
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
        $modules = Info::getModules();

        return view('dbg', [
        ]);
    }
}