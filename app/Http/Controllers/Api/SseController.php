<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SseService;
use Illuminate\Http\Request;

class SseController extends Controller
{
    public function connect(Request $request)
    {
        $service = app(SseService::class);

        $emitter = $service->addMember($request->get('token'));

        $emitter->open();
    }
}
