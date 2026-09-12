<?php

namespace DevInspector\Laravel;

use Closure;
use Illuminate\Http\Request;
use DevInspector\AuditCore;
use Throwable;

class AuditMiddleware
{
    public function handle(
        Request $request,
        Closure $next
    ) {
        $audit = AuditCore::getInstance();

        /*
         * Nunca monitora o próprio endpoint de ingestão
         * do DevInspector.
         */
        if ($audit->isIngestUrl($request->fullUrl())) {
            return $next($request);
        }

        /*
         * Mantém IDs vindos de outro serviço quando disponíveis.
         * Caso contrário, o AuditCore gera novos IDs.
         */
        $requestId = $request->header(
            'x-devinspector-request-id'
        );

        $traceId = $request->header(
            'x-devinspector-trace-id'
        );

        $spanId = $request->header(
            'x-devinspector-span-id'
        );

        AuditCore::beginRequest(
            $requestId,
            $traceId,
            $spanId,
            $request->fullUrl()
        );

        $ctx = AuditCore::getRequestContext();

        try {
            /*
             * Propaga os IDs para o Request do Laravel.
             */
            $request->headers->set(
                'x-devinspector-request-id',
                $ctx?->requestId ?? ''
            );

            $request->headers->set(
                'x-devinspector-trace-id',
                $ctx?->traceId ?? ''
            );

            $request->headers->set(
                'x-devinspector-span-id',
                $ctx?->spanId ?? ''
            );

            $startTime = microtime(true);

            try {
                $response = $next($request);
            } catch (Throwable $exception) {
                /*
                 * Mesmo quando a aplicação lança uma exceção,
                 * registra o erro com duração e contexto.
                 */
                $durationMs =
                    (microtime(true) - $startTime) * 1000;

                $audit->captureException(
                    $exception,
                    [
                        'method' =>
                            strtoupper($request->method()),

                        'url' =>
                            $request->fullUrl(),

                        'durationMs' =>
                            round($durationMs, 2),
                    ]
                );

                throw $exception;
            }

            $durationMs =
                (microtime(true) - $startTime) * 1000;

            $ctx = AuditCore::getRequestContext();

            $dbQueriesCount =
                $ctx?->queriesCount ?? 0;

            $slowQueryMs =
                $ctx?->slowQueries ?? 0.0;

            $statusCode =
                $response->getStatusCode();

            /*
             * Só envia request_metric quando:
             *
             * - HTTP >= 400
             * - requisição ultrapassou o threshold
             * - houve consulta lenta
             */
            $isError =
                $statusCode >= 400;

            $isSlowRequest =
                $durationMs >=
                $audit->getSlowThresholdMs();

            $hasSlowQuery =
                $slowQueryMs > 0;

            if (
                $isError ||
                $isSlowRequest ||
                $hasSlowQuery
            ) {
                $route = $request->route();

                $routeName = $route
                    ? $route->uri()
                    : $request->path();

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

            /*
             * Propaga os IDs também na resposta.
             */
            $response->headers->set(
                'x-devinspector-request-id',
                $ctx?->requestId ?? ''
            );

            $response->headers->set(
                'x-devinspector-trace-id',
                $ctx?->traceId ?? ''
            );

            $response->headers->set(
                'x-devinspector-span-id',
                $ctx?->spanId ?? ''
            );

            return $response;
        } finally {
            /*
             * O contexto não pode vazar para outra requisição.
             */
            AuditCore::clearRequest();
        }
    }
}