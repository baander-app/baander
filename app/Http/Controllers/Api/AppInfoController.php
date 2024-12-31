<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Integrations\Github\BaanderGhApi;

class AppInfoController extends Controller
{
    public function __construct(private readonly BaanderGhApi $ghApi)
    {
    }


    public function show()
    {
        $gh = $this->ghApi->getBaanderRepo();

        return response()->json([
            'repositoryName' => (string)$gh->full_name,
            'repositoryUrl'  => (string)$gh->html_url,
            'homepage'       => (string)$gh->homepage,
        ]);
    }
}