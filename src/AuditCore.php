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
    private const DEFAULT_ENDPOINT =
        'https://api.devinspector.com.br/api/ingest/track';

    private const MAX_BREADCRUMBS = 50;
    private const MAX_MESSAGE_LENGTH = 500;
    private const MAX_STACK_TRACE_LENGTH = 10000;
    private const MAX_METADATA_STRING_LENGTH = 5000;
    private const MAX_METRIC_NAME_LENGTH = 200;
    private const MAX_ARRAY_ITEMS = 100;
    private const MAX_SANITIZE_DEPTH = 10;

    private const SENSITIVE_KEYS = [
        'password',
        'passwd',
        'pwd',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'api_key',
        'apikey',
        'authorization',
        'cookie',
        'set_cookie',
        'credit_card',
        'card_number',
        'cvv',
        'cvc',
        'ssn',
        'private_key',
        'client_secret',
    ];

    private string $apiKey = 'PENDING_API_KEY';

    private string $endpoint = self::DEFAULT_ENDPOINT;

    private string $environment = 'production';

    private ?string $release = null;

    private float $slowThresholdMs = 300.0;

    public bool $initialized = false;

    private bool $listenersAttached = false;

    private static ?RequestContext $requestContext = null;

    private static ?AuditCore $instance = null;

    private ?array $user = null;

    private array $breadcrumbs = [];

    private function __construct()
    {
    }

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
            error_log(
                '[AuditSDK] API Key não fornecida no init().'
            );

            return;
        }

        $this->apiKey = trim($apiKey);

        if (!empty($endpoint)) {
            $this->endpoint = rtrim(trim($endpoint), '/');
        }

        if (!empty($environment)) {
            $this->environment = trim($environment);
        }

        if ($release !== null && trim($release) !== '') {
            $this->release = trim($release);
        }

        if (
            $slowThresholdMs !== null &&
            is_finite($slowThresholdMs) &&
            $slowThresholdMs >= 0
        ) {
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
        $targetUrl = trim($targetUrl);

        if ($targetUrl === '') {
            return false;
        }

        try {
            $targetUri = parse_url($targetUrl);
            $endpointUri = parse_url($this->endpoint);

            if (
                $targetUri === false ||
                $endpointUri === false
            ) {
                return false;
            }

            $targetHost = strtolower(
                $targetUri['host'] ?? ''
            );

            $endpointHost = strtolower(
                $endpointUri['host'] ?? ''
            );

            $targetPath = $this->normalizePath(
                $targetUri['path'] ?? ''
            );

            $endpointPath = $this->normalizePath(
                $endpointUri['path'] ?? ''
            );

            if (
                $targetHost === '' ||
                $endpointHost === ''
            ) {
                return false;
            }

            return
                $targetHost === $endpointHost &&
                $targetPath === $endpointPath;
        } catch (Throwable) {
            return false;
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
        ?string $spanId = null,
        array $metadata = []
    ): void {
        if (
            !$this->initialized ||
            trim($url) === '' ||
            trim($method) === ''
        ) {
            return;
        }

        if ($this->isIngestUrl($url)) {
            return;
        }

        $context = self::$requestContext;

        $requestId ??= $context?->requestId;
        $traceId ??= $context?->traceId;
        $spanId ??= $context?->spanId;

        $method = strtoupper(trim($method));

        $meta = array_merge(
            $metadata,
            [
                'environment' => $this->environment,
                'timestamp' => $this->getIsoTimestamp(),
                'route' => !empty($route)
                    ? $route
                    : $url,
                'dbQueriesCount' => max(0, $dbQueriesCount),
                'slowQueryMs' => round(
                    max(0.0, $slowQueryMs),
                    2
                ),
            ]
        );

        $payload = $this->createBasePayload(
            'request_metric'
        );

        $payload['message'] =
            "{$method} {$url} - {$statusCode}";

        $payload['method'] = $method;
        $payload['url'] = $url;
        $payload['statusCode'] = $statusCode;
        $payload['durationMs'] = round(
            max(0.0, $durationMs),
            2
        );
        $payload['browser'] = $userAgent;

        $this->addCorrelationIds(
            $payload,
            $requestId,
            $traceId,
            $spanId
        );

        $payload['metadata'] = $meta;

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
        if (!$this->initialized) {
            return;
        }

        $context = self::$requestContext;

        $requestId ??= $context?->requestId;
        $traceId ??= $context?->traceId;
        $spanId ??= $context?->spanId;

        $url ??= $context?->url ?? '';

        $operation = trim($operation);

        if ($operation === '') {
            $operation = 'unknown';
        }

        $durationMs = max(0.0, $durationMs);

        $meta = array_merge(
            $metadata,
            [
                'collection' => $collection ?? '',
                'operation' => $operation,
                'durationMs' => round(
                    $durationMs,
                    2
                ),
                'environment' => $this->environment,
                'timestamp' => $this->getIsoTimestamp(),
            ]
        );

        $payload = $this->createBasePayload(
            'db_query'
        );

        $payload['message'] =
            "DB {$operation}";

        $payload['url'] = $url;
        $payload['durationMs'] = round(
            $durationMs,
            2
        );

        $this->addCorrelationIds(
            $payload,
            $requestId,
            $traceId,
            $spanId
        );

        $payload['metadata'] = $meta;

        $this->send($payload);
    }

    public function captureError(
        Throwable $error,
        array $metadata = []
    ): void {
        if (!$this->initialized) {
            return;
        }

        $context = self::$requestContext;

        $rawMessage = trim(
            $error->getMessage()
        );

        if ($rawMessage === '') {
            $rawMessage = 'Erro Desconhecido';
        }

        $rawStack = $error->getTraceAsString();

        $meta = array_merge(
            $metadata,
            [
                'environment' => $this->environment,
                'timestamp' => $this->getIsoTimestamp(),
                'hResult' => $error->getCode(),
                'exceptionType' => get_class($error),
            ]
        );

        $payload = $this->createBasePayload(
            'error'
        );

        $payload['message'] = $this->truncate(
            $rawMessage,
            self::MAX_MESSAGE_LENGTH
        );

        $payload['stackTrace'] = $this->truncate(
            $rawStack,
            self::MAX_STACK_TRACE_LENGTH
        );

        $payload['url'] =
            $context?->url
            ?: $this->getCurrentUrl();

        $payload['browser'] =
            'PHP/' . PHP_VERSION;

        $this->addCorrelationIds(
            $payload,
            $context?->requestId,
            $context?->traceId,
            $context?->spanId
        );

        $payload['metadata'] = $meta;

        $this->send($payload);
    }

    public function captureException(
        Throwable $error,
        array $metadata = []
    ): void {
        $this->captureError(
            $error,
            $metadata
        );
    }

    public function captureMessage(
        string $message,
        array $metadata = []
    ): void {
        if (!$this->initialized) {
            return;
        }

        $context = self::$requestContext;

        $meta = array_merge(
            [
                'level' => 'info',
            ],
            $metadata,
            [
                'environment' => $this->environment,
                'timestamp' => $this->getIsoTimestamp(),
            ]
        );

        $payload = $this->createBasePayload(
            'message'
        );

        $payload['message'] = $this->truncate(
            $message,
            self::MAX_MESSAGE_LENGTH
        );

        $payload['stackTrace'] = '';

        $payload['url'] =
            $context?->url
            ?: $this->getCurrentUrl();

        $payload['browser'] =
            'PHP/' . PHP_VERSION;

        $this->addCorrelationIds(
            $payload,
            $context?->requestId,
            $context?->traceId,
            $context?->spanId
        );

        $payload['metadata'] = $meta;

        $this->send($payload);
    }

    public function captureMetric(
        string $metricName,
        float $metricValue,
        array $metadata = [],
        ?string $requestId = null,
        ?string $traceId = null,
        ?string $spanId = null
    ): void {
        $this->captureCustomMetric(
            $metricName,
            $metricValue,
            $metadata,
            $requestId,
            $traceId,
            $spanId
        );
    }

    public function captureCustomMetric(
        string $metricName,
        float $metricValue,
        array $metadata = [],
        ?string $requestId = null,
        ?string $traceId = null,
        ?string $spanId = null
    ): void {
        if (!$this->initialized) {
            return;
        }

        $metricName = trim($metricName);

        if ($metricName === '') {
            return;
        }

        if (
            !is_finite($metricValue) ||
            is_nan($metricValue)
        ) {
            return;
        }

        $metricName = $this->truncate(
            $metricName,
            self::MAX_METRIC_NAME_LENGTH
        );

        $context = self::$requestContext;

        $requestId ??= $context?->requestId;
        $traceId ??= $context?->traceId;
        $spanId ??= $context?->spanId;

        $meta = array_merge(
            $metadata,
            [
                'environment' => $this->environment,
                'timestamp' => $this->getIsoTimestamp(),
            ]
        );

        $payload = $this->createBasePayload(
            'custom_metric'
        );

        $payload['message'] =
            "Custom metric: {$metricName}";

        $payload['metricName'] = $metricName;
        $payload['metricValue'] = $metricValue;

        $this->addCorrelationIds(
            $payload,
            $requestId,
            $traceId,
            $spanId
        );

        $payload['metadata'] = $meta;

        $this->send($payload);
    }

    public function setUser(
        array $user
    ): void {
        $this->user = $this->sanitizeArray(
            $user
        );
    }

    public function getUser(): ?array
    {
        return $this->user;
    }

    public function clearUser(): void
    {
        $this->user = null;
    }

    public function addBreadcrumb(
        string $type,
        string $message = '',
        array $metadata = []
    ): void {
        $breadcrumb = [
            'timestamp' => $this->getIsoTimestamp(),
            'type' => trim($type),
            'message' => $this->truncate(
                $message,
                self::MAX_MESSAGE_LENGTH
            ),
        ];

        if (!empty($metadata)) {
            $breadcrumb['metadata'] =
                $this->sanitizeArray(
                    $metadata
                );
        }

        $this->breadcrumbs[] =
            $breadcrumb;

        if (
            count($this->breadcrumbs) >
            self::MAX_BREADCRUMBS
        ) {
            $this->breadcrumbs = array_slice(
                $this->breadcrumbs,
                -self::MAX_BREADCRUMBS
            );
        }
    }

    public function getBreadcrumbs(): array
    {
        return $this->breadcrumbs;
    }

    public function clearBreadcrumbs(): void
    {
        $this->breadcrumbs = [];
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

            $durationMs =
                (microtime(true) - $start) * 1000;

            if (
                $durationMs >=
                max(0.0, $thresholdMs)
            ) {
                if ($context !== null) {
                    $context->slowQueries +=
                        $durationMs;
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
            $durationMs =
                (microtime(true) - $start) * 1000;

            $this->captureException(
                $e,
                [
                    'operationName' =>
                        $operationName,
                    'durationMs' =>
                        round($durationMs, 2),
                ]
            );

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

        set_exception_handler(
            function (Throwable $exception): void {
                $this->captureError(
                    $exception,
                    [
                        'type' =>
                            'uncaught_exception',
                    ]
                );
            }
        );

        set_error_handler(
            function (
                int $severity,
                string $message,
                string $file,
                int $line
            ): bool {
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

    private function createBasePayload(
        string $type
    ): array {
        $payload = [
            'type' => $type,
            'environment' => $this->environment,
            'release' => $this->release,
        ];

        if ($this->user !== null) {
            $payload['user'] =
                $this->user;
        }

        if (!empty($this->breadcrumbs)) {
            $payload['breadcrumbs'] =
                $this->breadcrumbs;
        }

        return $payload;
    }

    private function addCorrelationIds(
        array &$payload,
        ?string $requestId,
        ?string $traceId,
        ?string $spanId
    ): void {
        if (!empty($requestId)) {
            $payload['requestId'] =
                $requestId;
        }

        if (!empty($traceId)) {
            $payload['traceId'] =
                $traceId;
        }

        if (!empty($spanId)) {
            $payload['spanId'] =
                $spanId;
        }
    }

    private function sanitizeArray(
        array $value
    ): array {
        $visited = [];

        return $this->sanitizeValue(
            $value,
            0,
            $visited
        );
    }

    private function sanitizeValue(
        mixed $value,
        int $depth,
        array &$visited
    ): mixed {
        if ($depth > self::MAX_SANITIZE_DEPTH) {
            return '[truncated]';
        }

        if (is_string($value)) {
            return $this->truncate(
                $value,
                self::MAX_METADATA_STRING_LENGTH
            );
        }

        if (
            is_int($value) ||
            is_bool($value) ||
            $value === null
        ) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value)
                ? $value
                : null;
        }

        if (is_array($value)) {
            return $this->sanitizeArrayRecursive(
                $value,
                $depth,
                $visited
            );
        }

        if (
            is_object($value) &&
            method_exists(
                $value,
                '__toString'
            )
        ) {
            try {
                return $this->truncate(
                    (string) $value,
                    self::MAX_METADATA_STRING_LENGTH
                );
            } catch (Throwable) {
                return '[object]';
            }
        }

        if (is_object($value)) {
            return '[object]';
        }

        return $value;
    }

    private function sanitizeArrayRecursive(
        array $value,
        int $depth,
        array &$visited
    ): array {
        if ($depth > self::MAX_SANITIZE_DEPTH) {
            return [
                '[truncated]' => true,
            ];
        }

        $result = [];
        $count = 0;

        foreach ($value as $key => $item) {
            if ($count >= self::MAX_ARRAY_ITEMS) {
                $result['[truncated_items]'] = true;
                break;
            }

            $keyString = (string) $key;

            if ($this->isSensitiveKey($keyString)) {
                $result[$keyString] =
                    '[REDACTED]';

                $count++;
                continue;
            }

            $result[$keyString] =
                $this->sanitizeValue(
                    $item,
                    $depth + 1,
                    $visited
                );

            $count++;
        }

        return $result;
    }

    private function isSensitiveKey(
        string $key
    ): bool {
        $normalized = strtolower(
            preg_replace(
                '/[^a-z0-9_]/i',
                '_',
                $key
            ) ?? $key
        );

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (
                $normalized === $sensitive ||
                str_contains(
                    $normalized,
                    $sensitive
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function truncate(
        mixed $value,
        int $maxLength = 5000
    ): mixed {
        if (!is_string($value)) {
            return $value;
        }

        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr(
            $value,
            0,
            $maxLength
        ) . '... [truncated]';
    }

    private function send(
        array $payload
    ): void {
        if (
            !$this->initialized ||
            empty($this->apiKey) ||
            $this->apiKey === 'PENDING_API_KEY' ||
            empty($this->endpoint)
        ) {
            return;
        }

        $payload = $this->sanitizeArray(
            $payload
        );

        $jsonPayload = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($jsonPayload === false) {
            error_log(
                '[DevInspector] Falha ao serializar o payload.'
            );

            return;
        }

        $ch = curl_init(
            $this->endpoint
        );

        if ($ch === false) {
            return;
        }

        curl_setopt_array(
            $ch,
            [
                CURLOPT_POST => true,

                CURLOPT_POSTFIELDS =>
                    $jsonPayload,

                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'x-api-key: ' .
                        $this->apiKey,
                ],

                CURLOPT_RETURNTRANSFER => true,

                CURLOPT_TIMEOUT => 3,

                CURLOPT_CONNECTTIMEOUT => 2,

                CURLOPT_FOLLOWLOCATION => false,

                CURLOPT_SSL_VERIFYPEER => true,

                CURLOPT_SSL_VERIFYHOST => 2,
            ]
        );

        $response = curl_exec($ch);

        $httpCode = curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        $curlError = curl_error($ch);

        if ($curlError !== '') {
            error_log(
                '[DevInspector] Falha ao enviar requisição para o painel: ' .
                $curlError
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
        return (
            new DateTime(
                'now',
                new DateTimeZone('UTC')
            )
        )->format(
            'Y-m-d\TH:i:s.v\Z'
        );
    }

    private function getCurrentUrl(): string
    {
        if (
            isset($_SERVER['HTTP_HOST']) &&
            isset($_SERVER['REQUEST_URI'])
        ) {
            $protocol =
                (
                    !empty($_SERVER['HTTPS']) &&
                    $_SERVER['HTTPS'] !== 'off'
                )
                    ? 'https'
                    : 'http';

            return
                "{$protocol}://" .
                $_SERVER['HTTP_HOST'] .
                $_SERVER['REQUEST_URI'];
        }

        return '';
    }

    private function normalizePath(
        string $path
    ): string {
        $path = '/' . ltrim(
            $path,
            '/'
        );

        if ($path !== '/') {
            $path = rtrim(
                $path,
                '/'
            );
        }

        return $path;
    }

    private static function generateId(
        string $prefix
    ): string {
        try {
            return $prefix .
                bin2hex(
                    random_bytes(16)
                );
        } catch (Throwable) {
            return $prefix .
                uniqid('', true);
        }
    }
}

function audit(): AuditCore
{
    return AuditCore::getInstance();
}