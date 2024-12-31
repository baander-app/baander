<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Gate;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    protected function denyWithStatus(string $message, int $status)
    {
        return response()->json([
            'message' => $message,
        ])->setStatusCode($status);
    }

    protected function noContent()
    {
        return response(null, 204);
    }

    protected function gateCheckViewDashboard()
    {
        if (!Gate::allows('viewDashboard')) {
            abort(403);
        }
    }

    protected function gateCheckExecuteJob()
    {
        if (!Gate::allows('executeJob')) {
            abort(403);
        }
    }
}
