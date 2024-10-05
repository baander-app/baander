<?php

namespace App\Http\Middleware;

use Closure;
use Inspector\Laravel\Middleware\InspectorOctaneMiddleware;

class InspectorMonitoringMiddleware extends InspectorOctaneMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     * @throws \Exception
     */
    public function handle($request, Closure $next)
    {
        $_SERVER['REQUEST_METHOD'] = $request->getMethod();
        $_SERVER['REQUEST_URI'] = $request->getRequestUri();
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/' . $request->getProtocolVersion();
        $_SERVER['HTTP_HOST'] = $request->getHost();
        $_SERVER['REMOTE_ADDR'] = $request->getClientIp();
        $_SERVER['SERVER_NAME'] = $request->server('SERVER_NAME');
        $_SERVER['SERVER_PORT'] = $request->getPort();
        $_SERVER['QUERY_STRING'] = $request->getQueryString();
        $_SERVER['SCRIPT_NAME'] = $request->server('SCRIPT_NAME');

        return parent::handle($request, $next);
    }
}