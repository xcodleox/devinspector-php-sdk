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
}

class AuditCore
{
    private string $apiKey = "PENDING_API_KEY";
    private string $endpoint = "https://api.devinspector.com.br/api/ingest/track";
    private string $environment = "production";
    public bool $initialized = false;
    private bool $listenersAttached = false;

    // Contexto estático para isolar métricas de APM por requisição
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

    // Gerenciamento de Contexto para Middlewares
    public static function beginRequest(): void
    {
        self::$requestContext = new RequestContext();
    }

    public static function getRequestContext(): ?RequestContext
    {
        return self::$requestContext;
    }

    public static function clearRequest(): void
    {
        self::$requestContext = null;
    }

    public function init(string $apiKey, ?string $endpoint = null, ?string $environment = null): void
    {
        if (empty(trim($apiKey))) {
            error_log("[AuditSDK] API Key não fornecida no init().");
            return;
        }

        $this->apiKey = $apiKey;
        if (!empty($endpoint)) $this->endpoint = $endpoint;
        if (!empty($environment)) $this->environment = $environment;

        $this->initialized = true;
        $this->listenGlobalErrors();
    }

    /**
     * Verifica dinamicamente se uma URL pertence ao servidor de ingestão do Dev Inspector.
     * Evita chamadas em loop em ambientes self-hosted (Coolify, VPS, etc).
     */
    public function isIngestUrl(string $targetUrl): bool
    {
        if (empty(trim($targetUrl))) {
            return false;
        }

        try {
            if (!empty($this->endpoint) && str_contains($targetUrl, $this->endpoint)) {
                return true;
            }

            $endpointUri = parse_url($this->endpoint);
            if ($endpointUri !== false) {
                $host = $endpointUri['host'] ?? '';
                $path = $endpointUri['path'] ?? '';

                if ((!empty($host) && str_contains($targetUrl, $host)) || 
                    (!empty($path) && str_contains($targetUrl, $path))) {
                    return true;
                }
            }

            return str_contains($targetUrl, "devinspector.com.br") || str_contains($targetUrl, "/ingest/track");
        } catch (Throwable) {
            return str_contains($targetUrl, "devinspector.com.br") || str_contains($targetUrl, "/ingest/track");
        }
    }

    // Sobrecarga mantendo retrocompatibilidade + suporte APM
    public function captureRequest(
        string $method, 
        string $url, 
        int $statusCode, 
        float $durationMs, 
        string $userAgent = '',
        ?string $route = null,
        int $dbQueriesCount = 0,
        float $slowQueryMs = 0.0
    ): void {
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
            'metadata' => $metadata,
        ];

        $this->send($payload);
    }

    public function captureError(Throwable $error, array $metadata = []): void
    {
        $rawMessage = $error->getMessage() ?: 'Erro Desconhecido';
        $rawStack = $error->getTraceAsString();

        $meta = array_merge($metadata, [
            'environment' => $this->environment,
            'timestamp' => $this->getIsoTimestamp(),
        ]);

        $payload = [
            'type' => 'error',
            'message' => $this->truncate($rawMessage, 500),
            'stackTrace' => $this->truncate($rawStack, 10000),
            'url' => $this->getCurrentUrl(),
            'browser' => 'PHP/' . PHP_VERSION,
            'metadata' => $this->truncate($meta, 5000),
        ];

        $this->send($payload);
    }

    public function captureException(Throwable $error, array $metadata = []): void
    {
        $this->captureError($error, $metadata);
    }

    public function captureMessage(string $message, array $metadata = []): void
    {
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
            'url' => $this->getCurrentUrl(),
            'browser' => 'PHP/' . PHP_VERSION,
            'metadata' => $this->truncate($meta, 5000),
        ];

        $this->send($payload);
    }

    // Utilitário de APM para monitorar operações de banco/serviços
    public function traceOperation(string $operationName, float $thresholdMs, callable $operation): mixed
    {
        if (self::$requestContext !== null) {
            self::$requestContext->queriesCount++;
        }

        $start = microtime(true);
        try {
            $result = $operation();
            $durationMs = (microtime(true) - $start) * 1000;

            if ($durationMs > $thresholdMs && self::$requestContext !== null) {
                self::$requestContext->slowQueries = max(self::$requestContext->slowQueries, $durationMs);
                $this->captureMessage("Slow Query detectada em [{$operationName}]", [
                    'durationMs' => $durationMs,
                    'operationName' => $operationName
                ]);
            }

            return $result;
        } catch (Throwable $e) {
            $durationMs = (microtime(true) - $start) * 1000;
            $this->captureException($e, [
                'operationName' => $operationName,
                'durationMs' => $durationMs
            ]);
            throw $e;
        }
    }

    private function listenGlobalErrors(): void
    {
        if ($this->listenersAttached) return;
        $this->listenersAttached = true;

        set_exception_handler(function (Throwable $exception) {
            $this->captureError($exception, ['type' => 'uncaught_exception']);
        });

        set_error_handler(function ($severity, $message, $file, $line) {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            $meta = [
                'type' => 'php_error',
                'file' => $file,
                'line' => $line,
            ];
            $this->captureError(new ErrorException($message, 0, $severity, $file, $line), $meta);
            return false;
        });
    }

    private function truncate(mixed $value, int $maxLength = 5000): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value) > $maxLength
                ? mb_substr($value, 0, $maxLength) . '... [truncated]'
                : $value;
        }

        if (is_array($value)) {
            $truncated = [];
            foreach ($value as $k => $v) {
                $truncated[$k] = $this->truncate($v, $maxLength);
            }
            return $truncated;
        }

        return $value;
    }

    private function send(array $payload): void
    {
        $activeApiKey = ($this->apiKey && $this->apiKey !== 'PENDING_API_KEY')
            ? $this->apiKey
            : 'dev-fallback-key';

        $jsonPayload = json_encode($payload);

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $activeApiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            error_log('[DevInspector] Falha ao enviar requisição para o painel: ' . curl_error($ch));
        } elseif ($httpCode >= 400) {
            error_log("[DevInspector] Erro na API ({$httpCode}): {$response}");
        }

        curl_close($ch);
    }

    private function getIsoTimestamp(): string
    {
        return (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    private function getCurrentUrl(): string
    {
        if (isset($_SERVER['HTTP_HOST']) && isset($_SERVER['REQUEST_URI'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            return "{$protocol}://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
        }
        return '';
    }
}

function audit(): AuditCore
{
    return AuditCore::getInstance();
}