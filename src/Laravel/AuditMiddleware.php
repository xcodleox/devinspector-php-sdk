<?php

namespace DevInspector\Laravel;

use Closure;
use Illuminate\Http\Request;
use DevInspector\AuditCore;

class AuditMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $audit = AuditCore::getInstance();

        // Evita monitorar requisições direcionadas ao próprio endpoint de ingestão
        if ($audit->isIngestUrl($request->fullUrl())) {
            return $next($request);
        }

        // Inicia o contexto de APM para esta requisição
        AuditCore::beginRequest();

        $startTime = microtime(true);

        $response = $next($request);

        $durationMs = (microtime(true) - $startTime) * 1000;

        // Recupera os contadores computados durante o ciclo da requisição
        $ctx = AuditCore::getRequestContext();
        $dbQueriesCount = $ctx ? $ctx->queriesCount : 0;
        $slowQueryMs = $ctx ? $ctx->slowQueries : 0.0;

        $audit->captureRequest(
            method: $request->method(),
            url: $request->fullUrl(),
            statusCode: $response->getStatusCode(),
            durationMs: $durationMs,
            userAgent: $request->userAgent() ?? '',
            route: $request->route() ? $request->route()->uri() : $request->path(),
            dbQueriesCount: $dbQueriesCount,
            slowQueryMs: $slowQueryMs
        );

        // Limpa o contexto para evitar vazamento de estado
        AuditCore::clearRequest();

        return $response;
    }
}