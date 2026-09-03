<?php

namespace DevInspector\Laravel;

use Closure;
use Illuminate\Http\Request;

class AuditMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $startTime = microtime(true);

        $response = $next($request);

        $durationMs = (microtime(true) - $startTime) * 1000;

        \DevInspector\audit()->captureRequest(
            method: $request->method(),
            url: $request->fullUrl(),
            statusCode: $response->getStatusCode(),
            durationMs: $durationMs,
            userAgent: $request->userAgent() ?? ''
        );

        return $response;
    }
}