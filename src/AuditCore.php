<?php

namespace DevInspector\Audit;

use Exception;
use Throwable;
use ErrorException;

#region Models & Data Structures

class AuditUser
{
    public function __construct(
        public ?string $id = null,
        public ?string $email = null,
        public ?string $username = null,
        public array $additionalData = []
    ) {}

    public function toArray(): array
    {
        return array_merge([
            'id' => $this->id,
            'email' => $this->email,
            'username' => $this->username,
        ], $this->additionalData);
    }
}

class Breadcrumb
{
    public function __construct(
        public string $type = '',
        public ?string $message = null,
        public ?array $metadata = null,
        public ?string $timestamp = null
    ) {}

    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp,
            'type' => $this->type,
            'message' => $this->message,
            'metadata' => $this->metadata,
        ];
    }
}

class InitOptions
{
    public function __construct(
        public string $apiKey,
        public ?string $endpoint = null,
        public ?string $apmEndpoint = null,
        public ?string $environment = null,
        public ?string $release = null,
        public ?int $slowThresholdMs = null,
        public ?int $requestTimeoutMs = null
    ) {}
}

class RequestMetricData
{
    public string $method = 'GET';
    public string $url = '';
    public int $statusCode = 0;
    public float $durationMs = 0.0;
    public ?string $userAgent = null;
    public ?string $route = null;
    public ?int $dbQueriesCount = null;
    public ?float $slowQueryMs = null;
    public ?string $requestId = null;
    public ?string $traceId = null;
    public ?string $spanId = null;
    public ?array $metadata = null;
}

class DbQueryData
{
    public string $operation = '';
    public ?string $collection = null;
    public float $durationMs = 0.0;
    public ?string $url = null;
    public ?string $requestId = null;
    public ?string $traceId = null;
    public ?string $spanId = null;
    public ?array $metadata = null;
}

class CustomMetricData
{
    public string $metricName = '';
    public float $metricValue = 0.0;
    public ?array $metadata = null;
    public ?string $requestId = null;
    public ?string $traceId = null;
    public ?string $spanId = null;
}

#endregion

#region Main AuditCore Implementation

class AuditCore
{
    private const DEFAULT_ENDPOINT = 'https://api.devinspector.com.br/api/ingest/track';
    private const DEFAULT_APM_ENDPOINT = 'https://api.devinspector.com.br/api/ingest/apm';
    private const MAX_BREADCRUMBS = 50;
    private const MAX_QUEUE_SIZE = 100; // Proteção contra OOM em workers
    private const MAX_STRING_LENGTH = 10000;
    private const MAX_METADATA_DEPTH = 6;
    private const DEFAULT_REQUEST_TIMEOUT_MS = 2000; // Reduzido para evitar trava prolongada na shutdown

    private const SENSITIVE_KEYS = [
        'password', 'senha', 'token', 'authorization', 'auth', 'bearer',
        'credit_card', 'creditcard', 'cartao', 'cvv', 'cpf', 'rg',
        'secret', 'private_key', 'privatekey'
    ];

    private static ?AuditCore $instance = null;

    private string $apiKey = '';
    private string $endpoint = self::DEFAULT_ENDPOINT;
    private string $apmEndpoint = self::DEFAULT_APM_ENDPOINT;
    private string $environment = 'production';
    private ?string $release = null;
    private int $slowThresholdMs = 300;
    private int $requestTimeoutMs = self::DEFAULT_REQUEST_TIMEOUT_MS;
    private ?AuditUser $user = null;
    private bool $initialized = false;
    private bool $listenersAttached = false;

    /** @var Breadcrumb[] */
    private array $breadcrumbs = [];

    /** @var array<array{endpoint: string, payload: array}> */
    private array $queue = [];

    public static function getInstance(): AuditCore
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(InitOptions $options): void
    {
        if (empty(trim($options->apiKey))) {
            throw new \InvalidArgumentException('DevInspector: apiKey é obrigatório.');
        }

        $this->apiKey = trim($options->apiKey);
        $this->endpoint = trim($options->endpoint ?? self::DEFAULT_ENDPOINT);
        $this->apmEndpoint = trim($options->apmEndpoint ?? self::DEFAULT_APM_ENDPOINT);
        $this->environment = trim($options->environment ?? 'production');
        $this->release = trim($options->release ?? '');
        $this->slowThresholdMs = max(0, $options->slowThresholdMs ?? 300);
        $this->requestTimeoutMs = max(100, $options->requestTimeoutMs ?? self::DEFAULT_REQUEST_TIMEOUT_MS);

        $this->initialized = true;

        $this->attachGlobalListeners();
    }

    #region Getters & User Management

    public function getApiKey(): string { return $this->apiKey; }
    public function getEndpoint(): string { return $this->endpoint; }
    public function getApmEndpoint(): string { return $this->apmEndpoint; }
    public function getEnvironment(): string { return $this->environment; }
    public function getRelease(): ?string { return $this->release; }
    public function getSlowThresholdMs(): int { return $this->slowThresholdMs; }
    public function getRequestTimeoutMs(): int { return $this->requestTimeoutMs; }
    public function isInitialized(): bool { return $this->initialized; }

    public function setUser(?AuditUser $user): void
    {
        $this->user = $user !== null ? $this->sanitizeData($user) : null;
    }

    public function getUser(): ?AuditUser
    {
        return $this->user;
    }

    public function clearUser(): void
    {
        $this->user = null;
    }

    #endregion

    #region Breadcrumbs & Context

    public function addBreadcrumb(Breadcrumb $breadcrumb): void
    {
        if (empty(trim($breadcrumb->type))) return;

        $type = mb_substr(trim($breadcrumb->type), 0, 200);
        $message = $breadcrumb->message !== null ? mb_substr($breadcrumb->message, 0, self::MAX_STRING_LENGTH) : null;

        $item = new Breadcrumb(
            $type,
            $message,
            $breadcrumb->metadata !== null ? $this->sanitizeData($breadcrumb->metadata) : null,
            $breadcrumb->timestamp ?: $this->getCurrentTimestamp()
        );

        $this->breadcrumbs[] = $item;

        if (count($this->breadcrumbs) > self::MAX_BREADCRUMBS) {
            array_shift($this->breadcrumbs);
        }
    }

    public function getBreadcrumbs(): array
    {
        return array_map(fn($b) => $this->cloneForQueue($b), $this->breadcrumbs);
    }

    public function clearBreadcrumbs(): void
    {
        $this->breadcrumbs = [];
    }

    public function getContext(): array
    {
        return [
            'environment' => $this->environment,
            'release' => $this->release,
            'user' => $this->cloneForQueue($this->getUser()),
            'breadcrumbs' => $this->getBreadcrumbs()
        ];
    }

    public function isIngestUrl(string $url): bool
    {
        if (empty(trim($url))) return false;

        try {
            return (str_starts_with($url, $this->endpoint) || str_starts_with($url, $this->apmEndpoint));
        } catch (Throwable $e) {
            return false;
        }
    }

    #endregion

    #region ID Generation

    public function createRequestId(): string { return $this->createId('req'); }
    public function createTraceId(): string { return $this->createId('trace'); }
    public function createSpanId(): string { return $this->createId('span'); }

    private function createId(string $prefix): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return sprintf('%s-%s', $prefix, vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4)));
    }

    #endregion

    #region Captures

    public function captureRequest(RequestMetricData $data): void
    {
        $url = trim($data->url);
        if (empty($url)) return;

        $metadata = $data->metadata ?? [];
        if ($data->route !== null) $metadata['route'] = $data->route;
        if ($data->dbQueriesCount !== null) $metadata['dbQueriesCount'] = $data->dbQueriesCount;
        if ($data->slowQueryMs !== null) $metadata['slowQueryMs'] = $data->slowQueryMs;
        $metadata['timestamp'] = $this->getCurrentTimestamp();

        $this->sendApm([
            'type' => 'request_metric',
            'method' => $this->normalizeMethod($data->method) ?? 'GET',
            'url' => $url,
            'statusCode' => $this->normalizeStatusCode($data->statusCode) ?? 0,
            'durationMs' => $this->normalizeDuration($data->durationMs) ?? 0,
            'browser' => $data->userAgent,
            'requestId' => $data->requestId,
            'traceId' => $data->traceId,
            'spanId' => $data->spanId,
            'environment' => $this->environment,
            'release' => $this->release,
            'user' => $this->cloneForQueue($this->getUser()),
            'breadcrumbs' => $this->getBreadcrumbs(),
            'metadata' => $this->cloneForQueue($metadata)
        ]);
    }

    public function captureDbQuery(DbQueryData $data): void
    {
        $operation = trim($data->operation);
        if (empty($operation)) return;

        $durationMs = $this->normalizeDuration($data->durationMs) ?? 0;
        $metadata = array_merge($data->metadata ?? [], [
            'operation' => $operation,
            'collection' => $data->collection ?? 'Unknown',
            'durationMs' => $durationMs,
            'timestamp' => $this->getCurrentTimestamp()
        ]);

        $this->sendApm([
            'type' => 'db_query',
            'message' => "Database operation: {$operation}",
            'url' => $data->url,
            'method' => 'DB',
            'durationMs' => $durationMs,
            'requestId' => $data->requestId,
            'traceId' => $data->traceId,
            'spanId' => $data->spanId,
            'environment' => $this->environment,
            'release' => $this->release,
            'user' => $this->cloneForQueue($this->getUser()),
            'breadcrumbs' => $this->getBreadcrumbs(),
            'metadata' => $this->cloneForQueue($metadata)
        ]);
    }

    public function captureMetric(string $metricName, float $metricValue, ?array $metadata = null, ?array $contextIds = null): void
    {
        $normalizedName = $this->normalizeMetricName($metricName);
        if (empty($normalizedName) || is_nan($metricValue) || is_infinite($metricValue)) return;

        $meta = array_merge($metadata ?? [], ['timestamp' => $this->getCurrentTimestamp()]);

        $this->sendApm([
            'type' => 'custom_metric',
            'metricName' => $normalizedName,
            'metricValue' => $metricValue,
            'requestId' => $contextIds['requestId'] ?? null,
            'traceId' => $contextIds['traceId'] ?? null,
            'spanId' => $contextIds['spanId'] ?? null,
            'environment' => $this->environment,
            'release' => $this->release,
            'user' => $this->cloneForQueue($this->getUser()),
            'breadcrumbs' => $this->getBreadcrumbs(),
            'metadata' => $this->cloneForQueue($meta)
        ]);
    }

    public function captureCustomMetric(CustomMetricData $data): void
    {
        $this->captureMetric($data->metricName, $data->metricValue, $data->metadata, [
            'requestId' => $data->requestId,
            'traceId' => $data->traceId,
            'spanId' => $data->spanId
        ]);
    }

    public function captureError(Throwable $error, ?array $metadata = null): void
    {
        $context = $metadata ?? [];
        $method = $this->normalizeMethod($context['method'] ?? null);
        $statusCode = $this->normalizeStatusCode($context['statusCode'] ?? $context['status'] ?? null);
        $url = isset($context['url']) ? trim((string)$context['url']) : null;

        unset($context['method'], $context['statusCode'], $context['status'], $context['requestId'], $context['traceId'], $context['spanId'], $context['durationMs']);
        $context['timestamp'] = $this->getCurrentTimestamp();

        $payload = [
            'type' => 'error',
            'message' => !empty($error->getMessage()) ? trim($error->getMessage()) : 'Erro desconhecido',
            'stackTrace' => $error->getTraceAsString(),
            'environment' => $this->environment,
            'release' => $this->release,
            'user' => $this->cloneForQueue($this->getUser()),
            'breadcrumbs' => $this->getBreadcrumbs(),
            'metadata' => $this->cloneForQueue($context)
        ];

        if ($url !== null) $payload['url'] = $url;
        if ($method !== null) $payload['method'] = $method;
        if ($statusCode !== null) $payload['statusCode'] = $statusCode;

        $this->sendError($payload);
    }

    public function captureException(mixed $error, ?array $metadata = null): void
    {
        $ex = ($error instanceof Throwable) ? $error : new Exception($this->errorToMessage($error));
        $this->captureError($ex, $metadata);
    }

    public function captureMessage(string $message, ?array $metadata = null): void
    {
        $normalizedMessage = trim($message);
        if (empty($normalizedMessage)) return;

        $meta = array_merge($metadata ?? [], ['timestamp' => $this->getCurrentTimestamp()]);

        $this->sendApm([
            'type' => 'message',
            'message' => mb_substr($normalizedMessage, 0, self::MAX_STRING_LENGTH),
            'environment' => $this->environment,
            'release' => $this->release,
            'user' => $this->cloneForQueue($this->getUser()),
            'breadcrumbs' => $this->getBreadcrumbs(),
            'metadata' => $this->cloneForQueue($meta)
        ]);
    }

    #endregion

    #region Queue Processing & Send

    /**
     * Utiliza curl_multi para enviar a fila inteira de forma concorrente,
     * evitando travar o servidor durante o encerramento da request.
     */
    public function flush(): void
    {
        if (empty($this->queue)) return;

        $items = $this->queue;
        $this->queue = [];

        $multiHandle = curl_multi_init();
        $curlHandles = [];

        foreach ($items as $index => $item) {
            if (empty($item['endpoint'])) continue;

            $json = json_encode($this->sanitizeData($item['payload']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            
            $ch = curl_init($item['endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => $this->requestTimeoutMs,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-api-key: ' . $this->apiKey,
                    'Connection: close' // Impede Keep-Alive mantendo conexões presas no FPM
                ]
            ]);

            curl_multi_add_handle($multiHandle, $ch);
            $curlHandles[] = $ch;
        }

        $active = null;
        do {
            $status = curl_multi_exec($multiHandle, $active);
            if ($active) {
                curl_multi_select($multiHandle);
            }
        } while ($active && $status == CURLM_OK);

        foreach ($curlHandles as $ch) {
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }
        
        curl_multi_close($multiHandle);
    }

    private function sendError(array $payload): void { $this->enqueue($this->endpoint, $payload); }
    private function sendApm(array $payload): void { $this->enqueue($this->apmEndpoint, $payload); }

    private function enqueue(string $endpoint, array $payload): void
    {
        if (!$this->initialized || empty($this->apiKey)) return;

        if (count($this->queue) >= self::MAX_QUEUE_SIZE) {
            $this->flush(); // Força o envio se a fila encher durante uma rotina longa
        }

        $this->queue[] = [
            'endpoint' => $endpoint,
            'payload' => $this->cloneForQueue($payload)
        ];
    }

    private function attachGlobalListeners(): void
    {
        if ($this->listenersAttached) return;
        $this->listenersAttached = true;

        set_exception_handler(function (Throwable $ex) {
            $this->captureError($ex, ['platform' => 'php', 'fatal' => true]);
            $this->flush();
        });

        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) {
            if (!(error_reporting() & $errno)) return false;
            
            $ex = new ErrorException($errstr, 0, $errno, $errfile, $errline);
            $this->captureError($ex, ['platform' => 'php', 'fatal' => false]);
            return true;
        });

        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                $ex = new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
                $this->captureError($ex, ['platform' => 'php', 'fatal' => true]);
            }
            $this->flush();
        });
    }

    #endregion

    #region Helpers & Sanitization

    private function getCurrentTimestamp(): string
    {
        return (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = preg_replace('/[\s-]/', '_', mb_strtolower($key));
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($normalized, $sensitive)) {
                return true;
            }
        }
        return false;
    }

    private function sanitizeData(mixed $value, int $depth = 0, array &$seen = []): mixed
    {
        if ($value === null || is_scalar($value)) {
            if (is_string($value) && mb_strlen($value) > self::MAX_STRING_LENGTH) {
                return mb_substr($value, 0, self::MAX_STRING_LENGTH) . '...[truncated]';
            }
            return $value;
        }

        if ($depth >= self::MAX_METADATA_DEPTH) return '[MaxDepth]';

        if (is_object($value)) {
            $oid = spl_object_hash($value);
            if (isset($seen[$oid])) return '[Circular]';
            $seen[$oid] = true;

            $value = method_exists($value, 'toArray') ? $value->toArray() : get_object_vars($value);
        }

        if (is_array($value)) {
            $result = [];
            $count = 0;
            foreach ($value as $key => $val) {
                if ($count++ >= 100) break;
                
                if ($this->isSensitiveKey((string)$key)) {
                    $result[$key] = '[REDACTED]';
                } else {
                    $result[$key] = $this->sanitizeData($val, $depth + 1, $seen);
                }
            }
            return $result;
        }

        return null;
    }

    private function cloneForQueue(mixed $value): mixed
    {
        return $this->sanitizeData($value);
    }

    private function errorToMessage(mixed $error): string
    {
        if ($error instanceof Throwable) return !empty($error->getMessage()) ? $error->getMessage() : 'Erro desconhecido';
        if (is_string($error)) return !empty($error) ? $error : 'Erro desconhecido';

        try {
            return json_encode($this->sanitizeData($error), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (Throwable) {
            return 'Erro desconhecido';
        }
    }

    private function normalizeMethod(mixed $method): ?string
    {
        $val = mb_strtoupper(trim((string)$method));
        return empty($val) ? null : $val;
    }

    private function normalizeStatusCode(mixed $statusCode): ?int
    {
        return is_numeric($statusCode) ? (int)$statusCode : null;
    }

    private function normalizeDuration(mixed $durationMs): ?float
    {
        return is_numeric($durationMs) ? max(0, round((float)$durationMs)) : null;
    }

    private function normalizeMetricName(mixed $metricName): string
    {
        $val = trim((string)($metricName ?? ''));
        return mb_strlen($val) > 200 ? mb_substr($val, 0, 200) : $val;
    }

    #endregion
}

#endregion

#region Middleware / HTTP Interceptor

class AuditGuzzleMiddleware
{
    private AuditCore $auditCore;

    public function __construct(?AuditCore $auditCore = null)
    {
        $this->auditCore = $auditCore ?? AuditCore::getInstance();
    }

    public function __invoke(callable $handler): callable
    {
        return function ($request, array $options) use ($handler) {
            $requestUrl = (string) $request->getUri();
            
            if (!empty($requestUrl) && $this->auditCore->isIngestUrl($requestUrl)) {
                return $handler($request, $options);
            }

            $method = mb_strtoupper($request->getMethod());
            $requestId = $this->auditCore->createRequestId();
            $traceId = $this->auditCore->createTraceId();
            $spanId = $this->auditCore->createSpanId();

            $request = $request
                ->withHeader('x-devinspector-request-id', $requestId)
                ->withHeader('x-devinspector-trace-id', $traceId)
                ->withHeader('x-devinspector-span-id', $spanId);

            $startTime = microtime(true);

            return $handler($request, $options)->then(
                function ($response) use ($method, $requestUrl, $requestId, $traceId, $spanId, $startTime, $request) {
                    $durationMs = round((microtime(true) - $startTime) * 1000);
                    $statusCode = $response->getStatusCode();

                    $this->auditCore->addBreadcrumb(new Breadcrumb(
                        'http',
                        "{$method} {$requestUrl}",
                        [
                            'phase' => 'complete',
                            'statusCode' => $statusCode,
                            'durationMs' => $durationMs,
                            'requestId' => $requestId,
                            'traceId' => $traceId,
                            'spanId' => $spanId
                        ]
                    ));

                    if ($statusCode >= 400 || $durationMs >= $this->auditCore->getSlowThresholdMs()) {
                        $reqData = new RequestMetricData();
                        $reqData->method = $method;
                        $reqData->url = $requestUrl;
                        $reqData->statusCode = $statusCode;
                        $reqData->durationMs = $durationMs;
                        $reqData->userAgent = $request->getHeaderLine('User-Agent');
                        $reqData->requestId = $requestId;
                        $reqData->traceId = $traceId;
                        $reqData->spanId = $spanId;

                        $this->auditCore->captureRequest($reqData);
                    }

                    return $response;
                },
                function ($reason) use ($method, $requestUrl, $requestId, $traceId, $spanId, $startTime, $request) {
                    $durationMs = round((microtime(true) - $startTime) * 1000);

                    $this->auditCore->addBreadcrumb(new Breadcrumb(
                        'http_error',
                        "{$method} {$requestUrl}",
                        [
                            'phase' => 'error',
                            'statusCode' => 0,
                            'durationMs' => $durationMs,
                            'requestId' => $requestId,
                            'traceId' => $traceId,
                            'spanId' => $spanId
                        ]
                    ));

                    $reqData = new RequestMetricData();
                    $reqData->method = $method;
                    $reqData->url = $requestUrl;
                    $reqData->statusCode = 0;
                    $reqData->durationMs = $durationMs;
                    $reqData->userAgent = $request->getHeaderLine('User-Agent');
                    $reqData->requestId = $requestId;
                    $reqData->traceId = $traceId;
                    $reqData->spanId = $spanId;

                    $this->auditCore->captureRequest($reqData);

                    if ($reason instanceof Throwable) {
                        $this->auditCore->captureError($reason, [
                            'url' => $requestUrl,
                            'method' => $method
                        ]);
                    }

                    return \GuzzleHttp\Promise\Create::rejectionFor($reason);
                }
            );
        };
    }
}