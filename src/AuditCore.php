<?php

namespace DevInspector;

use Throwable;
use ErrorException;
use DateTime;
use DateTimeZone;

class RequestContext
{
    public int $queriesCount = 0;
    public float $slowQueries = 0.0;
    public ?string $requestId = null;
    public ?string $traceId = null;
    public ?string $spanId = null;
    public string $url = '';
}

class AuditCore
{
    private string $apiKey = "PENDING_API_KEY";
    private string $endpoint = "https://api.devinspector.com.br/api/ingest/track";
    private string $environment = "production";
    private ?string $release = null;
    private float $slowThresholdMs = 300.0;

    public bool $initialized = false;

    private bool $listenersAttached = false;

    private static ?RequestContext $requestContext = null;

    private static ?AuditCore $instance = null;

    private function __construct() {}

    public static function getInstance(): AuditCore
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function beginRequest(
        ?string $requestId = null,
        ?string $traceId = null,
        ?string $spanId = null,
        string $url = ''
    ): void {
        self::$requestContext = new RequestContext();

        self::$requestContext->requestId = !empty($requestId)
            ? $requestId
            : self::generateId('req_');

        self::$requestContext->traceId = !empty($traceId)
            ? $traceId
            : self::generateId('trace_');

        self::$requestContext->spanId = !empty($spanId)
            ? $spanId
            : self::generateId('span_');

        self::$requestContext->url = $url;
    }

    public static function getRequestContext(): ?RequestContext
    {
        return self::$requestContext;
    }

    public static function clearRequest(): void
    {
        self::$requestContext = null;
    }

    public function init(
        string $apiKey,
        ?string $endpoint = null,
        ?string $environment = null
    ): void {
        $this->initAdvanced(
            $apiKey,
            $endpoint,
            $environment,
            null,
            null
        );
    }

    public function initAdvanced(
        string $apiKey,
        ?string $endpoint = null,
        ?string $environment = null,
        ?string $release = null,
        ?float $slowThresholdMs = null
    ): void {
        if (empty(trim($apiKey))) {
            error_log("[AuditSDK] API Key não fornecida no init().");
            return;
        }

        $this->apiKey = $apiKey;

        if (!empty($endpoint)) {
            $this->endpoint = $endpoint;
        }

        if (!empty($environment)) {
            $this->environment = $environment;
        }

        if (!empty($release)) {
            $this->release = $release;
        }

        if ($slowThresholdMs !== null && $slowThresholdMs >= 0) {
            $this->slowThresholdMs = $slowThresholdMs;
        }

        $this->initialized = true;

        $this->listenGlobalErrors();
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function getRelease(): ?string
    {
        return $this->release;
    }

    public function getSlowThresholdMs(): float
    {
        return $this->slowThresholdMs;
    }

    public function isIngestUrl(string $targetUrl): bool
    {
        if (empty(trim($targetUrl))) {
            return false;
        }

        try {
            if (
                !empty($this->endpoint) &&
                str_contains($targetUrl, $this->endpoint)
            ) {
                return true;
            }

            $endpointUri = parse_url($this->endpoint);

            if ($endpointUri !== false) {
                $host = $endpointUri['host'] ?? '';
                $path = $endpointUri['path'] ?? '';

                if (
                    (!empty($host) && str_contains($targetUrl, $host)) ||
                    (!empty($path) && str_contains($targetUrl, $path))
                ) {
                    return true;
                }
            }

            return str_contains($targetUrl, 'devinspector.com.br') ||
                str_contains($targetUrl, '/ingest/track');
        } catch (Throwable) {
            return str_contains($targetUrl, 'devinspector.com.br') ||
                str_contains($targetUrl, '/ingest/track');
        }
    }

    public function captureRequest(
        string $method,
        string $url,
        int $statusCode,
        float $durationMs,
        string $userAgent = '',
        ?string $route = null,
        int $dbQueriesCount = 0,
        float $slowQueryMs = 0.0,
        ?string $requestId = null,
        ?string $traceId = null,
        ?string $spanId = null
    ): void {
        $context = self::$requestContext;

        $requestId ??= $context?->requestId;
        $traceId ??= $context?->traceId;
        $spanId ??= $context?->spanId;

        $metadata = [
            'environment' => $this->environment,
            'timestamp' => $this->getIsoTimestamp(),
            'route' => !empty($route) ? $route : $url,
            'dbQueriesCount' => $dbQueriesCount,
            'slowQueryMs' => round($slowQueryMs, 2),
        ];

        $payload = [
            'type' => 'request_metric',
            'message' => "{$method} {$url} - {$statusCode}",
            'method' => $method,
            'url' => $url,
            'statusCode' => $statusCode,
            'durationMs' => round($durationMs, 2),
            'browser' => $userAgent,
            'environment' => $this->environment,
            'release' => $this->release,
            'requestId' => $requestId ?? '',
            'traceId' => $traceId ?? '',
            'spanId' => $spanId ?? '',
            'metadata' => $metadata,
        ];

        $this->send($payload);
    }

    public function captureDbQuery(
        string $operation,
        ?string $collection,
        float $durationMs,
        ?string $url = null,
        ?string $requestId = null,
        ?string $traceId = null,
        ?string $spanId = null,
        array $metadata = []
    ): void {
        $context = self::$requestContext;

        $requestId ??= $context?->requestId;
        $traceId ??= $context?->traceId;
        $spanId ??= $context?->spanId;
        $url ??= $context?->url ?? '';

        $meta = array_merge($metadata, [
            'collection' => $collection ?? '',
            'operation' => $operation,
            'durationMs' => round($durationMs, 2),
            'environment' => $this->environment,
            'timestamp' => $this->getIsoTimestamp(),
        ]);

        $payload = [
            'type' => 'db_query',
            'message' => "DB {$operation}",
            'url' => $url,
            'durationMs' => round($durationMs, 2),
            'environment' => $this->environment,
            'release' => $this->release,
            'requestId' => $requestId ?? '',
            'traceId' => $traceId ?? '',
            'spanId' => $spanId ?? '',
            'metadata' => $this->truncate($meta, 5000),
        ];

        $this->send($payload);
    }

    public function captureError(
        Throwable $error,
        array $metadata = []
    ): void {
        $context = self::$requestContext;

        $rawMessage = $error->getMessage() ?: 'Erro Desconhecido';
        $rawStack = $error->getTraceAsString();

        $meta = array_merge($metadata, [
            'environment' => $this->environment,
            'timestamp' => $this->getIsoTimestamp(),
            'hResult' => $error->getCode(),
        ]);

        $payload = [
            'type' => 'error',
            'message' => $this->truncate($rawMessage, 500),
            'stackTrace' => $this->truncate($rawStack, 10000),
            'url' => $context?->url ?: $this->getCurrentUrl(),
            'browser' => 'PHP/' . PHP_VERSION,
            'environment' => $this->environment,
            'release' => $this->release,
            'requestId' => $context?->requestId ?? '',
            'traceId' => $context?->traceId ?? '',
            'spanId' => $context?->spanId ?? '',
            'metadata' => $this->truncate($meta, 5000),
        ];

        $this->send($payload);
    }

    public function captureException(
        Throwable $error,
        array $metadata = []
    ): void {
        $this->captureError($error, $metadata);
    }

    public function captureMessage(
        string $message,
        array $metadata = []
    ): void {
        $context = self::$requestContext;

        $meta = array_merge([
            'level' => 'info',
        ], $metadata, [
            'environment' => $this->environment,
            'timestamp' => $this->getIsoTimestamp(),
        ]);

        $payload = [
            'type' => 'message',
            'message' => $this->truncate($message, 500),
            'stackTrace' => '',
            'url' => $context?->url ?: $this->getCurrentUrl(),
            'browser' => 'PHP/' . PHP_VERSION,
            'environment' => $this->environment,
            'release' => $this->release,
            'requestId' => $context?->requestId ?? '',
            'traceId' => $context?->traceId ?? '',
            'spanId' => $context?->spanId ?? '',
            'metadata' => $this->truncate($meta, 5000),
        ];

        $this->send($payload);
    }

    public function traceOperation(
        string $operationName,
        float $thresholdMs,
        callable $operation
    ): mixed {
        $context = self::$requestContext;

        if ($context !== null) {
            $context->queriesCount++;
        }

        $start = microtime(true);

        try {
            $result = $operation();

            $durationMs = (microtime(true) - $start) * 1000;

            if ($durationMs >= $thresholdMs) {
                if ($context !== null) {
                    $context->slowQueries = max(
                        $context->slowQueries,
                        $durationMs
                    );
                }

                $this->captureDbQuery(
                    $operationName,
                    null,
                    $durationMs,
                    $context?->url,
                    $context?->requestId,
                    $context?->traceId,
                    $context?->spanId
                );
            }

            return $result;
        } catch (Throwable $e) {
            $durationMs = (microtime(true) - $start) * 1000;

            $this->captureException($e, [
                'operationName' => $operationName,
                'durationMs' => round($durationMs, 2),
            ]);

            throw $e;
        }
    }

    public function traceOperationWithDefaultThreshold(
        string $operationName,
        callable $operation
    ): mixed {
        return $this->traceOperation(
            $operationName,
            $this->slowThresholdMs,
            $operation
        );
    }

    private function listenGlobalErrors(): void
    {
        if ($this->listenersAttached) {
            return;
        }

        $this->listenersAttached = true;

        set_exception_handler(function (Throwable $exception) {
            $this->captureError(
                $exception,
                ['type' => 'uncaught_exception']
            );
        });

        set_error_handler(
            function (
                $severity,
                $message,
                $file,
                $line
            ) {
                if (!(error_reporting() & $severity)) {
                    return false;
                }

                $meta = [
                    'type' => 'php_error',
                    'file' => $file,
                    'line' => $line,
                ];

                $this->captureError(
                    new ErrorException(
                        $message,
                        0,
                        $severity,
                        $file,
                        $line
                    ),
                    $meta
                );

                return false;
            }
        );
    }

    private function truncate(
        mixed $value,
        int $maxLength = 5000
    ): mixed {
        if (is_string($value)) {
            return mb_strlen($value) > $maxLength
                ? mb_substr($value, 0, $maxLength) . '... [truncated]'
                : $value;
        }

        if (is_array($value)) {
            $truncated = [];

            foreach ($value as $key => $item) {
                $truncated[$key] = $this->truncate(
                    $item,
                    $maxLength
                );
            }

            return $truncated;
        }

        return $value;
    }

    private function send(array $payload): void
    {
        if (
            !$this->initialized ||
            empty($this->apiKey) ||
            $this->apiKey === 'PENDING_API_KEY'
        ) {
            return;
        }

        $jsonPayload = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($jsonPayload === false) {
            error_log(
                '[DevInspector] Falha ao serializar o payload.'
            );
            return;
        }

        $ch = curl_init($this->endpoint);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            error_log(
                '[DevInspector] Falha ao enviar requisição para o painel: ' .
                curl_error($ch)
            );
        } elseif ($httpCode >= 400) {
            error_log(
                "[DevInspector] Erro na API ({$httpCode}): {$response}"
            );
        }

        curl_close($ch);
    }

    private function getIsoTimestamp(): string
    {
        return (new DateTime(
            'now',
            new DateTimeZone('UTC')
        ))->format('Y-m-d\TH:i:s.v\Z');
    }

    private function getCurrentUrl(): string
    {
        if (
            isset($_SERVER['HTTP_HOST']) &&
            isset($_SERVER['REQUEST_URI'])
        ) {
            $protocol = (
                !empty($_SERVER['HTTPS']) &&
                $_SERVER['HTTPS'] !== 'off'
            ) ? 'https' : 'http';

            return "{$protocol}://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
        }

        return '';
    }

    private static function generateId(string $prefix): string
    {
        return $prefix . bin2hex(random_bytes(16));
    }
}

function audit(): AuditCore
{
    return AuditCore::getInstance();
}