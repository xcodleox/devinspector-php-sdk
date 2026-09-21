<?php

namespace DevInspector\Laravel;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use DevInspector\AuditCore;
use Throwable;

class AuditMiddleware
{
    /**
     * Lida com a requisição principal
     */
    public function handle(Request $request, Closure $next)
    {
        $audit = AuditCore::getInstance();

        if ($audit->isIngestUrl($request->fullUrl())) {
            return $next($request);
        }

        AuditCore::beginRequest(
            $request->header('x-devinspector-request-id'),
            $request->header('x-devinspector-trace-id'),
            $request->header('x-devinspector-span-id'),
            $request->fullUrl()
        );

        $ctx = AuditCore::getRequestContext();

        // Propaga para o Request
        $request->headers->set('x-devinspector-request-id', $ctx?->requestId ?? '');
        $request->headers->set('x-devinspector-trace-id', $ctx?->traceId ?? '');
        $request->headers->set('x-devinspector-span-id', $ctx?->spanId ?? '');

        // Adiciona o tempo de início no request para usarmos no terminate()
        $request->attributes->set('devinspector_start_time', microtime(true));

        try {
            $response = $next($request);
            
            // Propaga IDs na Resposta
            $response->headers->set('x-devinspector-request-id', $ctx?->requestId ?? '');
            $response->headers->set('x-devinspector-trace-id', $ctx?->traceId ?? '');
            $response->headers->set('x-devinspector-span-id', $ctx?->spanId ?? '');

            return $response;

        } catch (Throwable $exception) {
            // Captura a exceção, mas deixa o Laravel lidar com ela (ou estourar)
            $startTime = $request->attributes->get('devinspector_start_time', microtime(true));
            $durationMs = (microtime(true) - $startTime) * 1000;

            $audit->captureException($exception, [
                'method' => strtoupper($request->method()),
                'url' => $request->fullUrl(),
                'durationMs' => round($durationMs, 2),
            ]);

            throw $exception;
        }
    }

    /**
     * Executado após a resposta ser enviada ao navegador (Não bloqueia o usuário)
     */
    public function terminate(Request $request, Response $response): void
    {
        $audit = AuditCore::getInstance();

        if ($audit->isIngestUrl($request->fullUrl())) {
            return;
        }

        $startTime = $request->attributes->get('devinspector_start_time');
        if (!$startTime) {
            return;
        }

        $durationMs = (microtime(true) - $startTime) * 1000;
        $ctx = AuditCore::getRequestContext();
        $statusCode = $response->getStatusCode();

        $dbQueriesCount = $ctx?->queriesCount ?? 0;
        $slowQueryMs = $ctx?->slowQueries ?? 0.0;

        $isError = $statusCode >= 400;
        $isSlowRequest = $durationMs >= $audit->getSlowThresholdMs();
        $hasSlowQuery = $slowQueryMs > 0;

        if ($isError || $isSlowRequest || $hasSlowQuery) {
            $route = $request->route();
            $routeName = $route ? $route->uri() : $request->path();

            $audit->captureRequest(
                $request->method(),
                $request->fullUrl(),
                $statusCode,
                $durationMs,
                $request->userAgent() ?? '',
                $routeName,
                $dbQueriesCount,
                $slowQueryMs,
                $ctx?->requestId,
                $ctx?->traceId,
                $ctx?->spanId
            );
        }

        // Limpa o contexto apenas no final de todo o ciclo
        AuditCore::clearRequest();
    }
}