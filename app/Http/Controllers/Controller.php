<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

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
}
