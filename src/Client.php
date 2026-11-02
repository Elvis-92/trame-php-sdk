<?php

declare(strict_types=1);

namespace Trame;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

final class Client
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $operations = null;

    private ?string $sessionCookie = null;

    private readonly string $sessionEnvironment;

    private readonly string $baseUrl;

    private readonly ?string $sessionOrigin;

    public const DEFAULT_BASE_URL = 'https://api.preprod.trame.africa/v1';

    public const LIVE_BASE_URL = 'https://api.trame.africa/v1';

    public function __construct(
        private readonly ?string $apiKey,
        private readonly ClientInterface $httpClient,
        ?string $baseUrl = null,
        ?string $sessionEnvironment = null,
        ?string $sessionOrigin = null,
        private readonly float $timeout = 30.0,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('An API key cannot be empty.');
        }
        if (! is_finite($timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException('The request timeout must be positive.');
        }

        if ($apiKey !== null && ! str_starts_with($apiKey, 'tr_test_') && ! str_starts_with($apiKey, 'tr_live_')) {
            throw new \InvalidArgumentException('The API key must start with tr_test_ or tr_live_.');
        }

        $sessionEnvironment ??= $apiKey !== null && str_starts_with($apiKey, 'tr_live_') ? 'live' : 'test';
        if (! in_array($sessionEnvironment, ['test', 'live'], true)) {
            throw new \InvalidArgumentException('The session environment must be test or live.');
        }
        $keyEnvironment = $apiKey !== null && str_starts_with($apiKey, 'tr_live_') ? 'live' : 'test';
        if ($apiKey !== null && $keyEnvironment !== $sessionEnvironment) {
            throw new \InvalidArgumentException('The API key and customer session must use the same environment.');
        }
        $baseUrl ??= $apiKey === null && $sessionEnvironment === 'live'
            ? self::LIVE_BASE_URL
            : self::DEFAULT_BASE_URL;

        $host = parse_url($baseUrl, PHP_URL_HOST);
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        $basePath = rtrim((string) parse_url($baseUrl, PHP_URL_PATH), '/');
        $port = parse_url($baseUrl, PHP_URL_PORT);
        $user = parse_url($baseUrl, PHP_URL_USER);
        $password = parse_url($baseUrl, PHP_URL_PASS);
        $query = parse_url($baseUrl, PHP_URL_QUERY);
        $fragment = parse_url($baseUrl, PHP_URL_FRAGMENT);
        $expectedHost = $sessionEnvironment === 'live'
            ? 'api.trame.africa'
            : 'api.preprod.trame.africa';
        if ($scheme !== 'https'
            || $port !== null
            || $user !== null
            || $password !== null
            || $query !== null
            || $fragment !== null
            || $basePath !== '/v1'
            || $host !== $expectedHost) {
            throw new \InvalidArgumentException(
                sprintf('The %s credentials must use %s.', $sessionEnvironment, $expectedHost),
            );
        }
        if ($sessionOrigin !== null) {
            $originScheme = parse_url($sessionOrigin, PHP_URL_SCHEME);
            $originHost = parse_url($sessionOrigin, PHP_URL_HOST);
            $originPort = parse_url($sessionOrigin, PHP_URL_PORT);
            $originPath = parse_url($sessionOrigin, PHP_URL_PATH);
            if ($originScheme !== 'https'
                || ! is_string($originHost)
                || ($originPath !== null && $originPath !== '' && $originPath !== '/')
                || parse_url($sessionOrigin, PHP_URL_USER) !== null
                || parse_url($sessionOrigin, PHP_URL_PASS) !== null
                || parse_url($sessionOrigin, PHP_URL_QUERY) !== null
                || parse_url($sessionOrigin, PHP_URL_FRAGMENT) !== null) {
                throw new \InvalidArgumentException('The session origin must be an HTTPS origin without a path.');
            }
            $sessionOrigin = 'https://'.strtolower($originHost)
                .($originPort === null || $originPort === 443 ? '' : ':'.$originPort);
        }
        $this->sessionEnvironment = $sessionEnvironment;
        $this->baseUrl = $baseUrl;
        $this->sessionOrigin = $sessionOrigin;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function declarePayment(array|PaymentDeclaration $payload, string $idempotencyKey): array
    {
        return $this->post(
            '/payments/declared',
            $payload instanceof PaymentDeclaration ? $payload->toArray() : $payload,
            $idempotencyKey,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function reportEvent(array|EventCreate $payload, string $idempotencyKey): array
    {
        return $this->post(
            '/events',
            $payload instanceof EventCreate ? $payload->toArray() : $payload,
            $idempotencyKey,
        );
    }

    /** @return array<string, mixed> */
    public function getPayment(string $paymentId): array
    {
        return $this->request('GET', '/payments/'.rawurlencode($paymentId));
    }

    /** @return array<string, mixed> */
    public function releasePayment(string $paymentId, string $idempotencyKey): array
    {
        return $this->post('/payments/'.rawurlencode($paymentId).'/release', [], $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function confirmPayment(string $paymentId, string $idempotencyKey): array
    {
        return $this->post('/payments/'.rawurlencode($paymentId).'/confirm', [], $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function refundPayment(string $paymentId, array $payload, string $idempotencyKey): array
    {
        return $this->post('/payments/'.rawurlencode($paymentId).'/refunds', $payload, $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function declareRefund(string $paymentId, array $payload, string $idempotencyKey): array
    {
        return $this->post('/payments/'.rawurlencode($paymentId).'/refunds/declared', $payload, $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createBeneficiary(array $payload): array
    {
        return $this->request('POST', '/beneficiaries', $payload);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    public function listBeneficiaries(array $query = []): array
    {
        return $this->request('GET', '/beneficiaries', query: $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function renameBeneficiary(string $beneficiaryId, array $payload): array
    {
        return $this->request('PATCH', '/beneficiaries/'.rawurlencode($beneficiaryId), $payload);
    }

    /** @return array<string, mixed> */
    public function createRule(string $key): array
    {
        return $this->request('POST', '/rules', ['key' => $key]);
    }

    /** @return array<string, mixed> */
    public function listRules(): array
    {
        return $this->request('GET', '/rules');
    }

    /** @return array<string, mixed> */
    public function getRule(string $key): array
    {
        return $this->request('GET', '/rules/'.rawurlencode($key));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createRuleVersion(string $key, array $payload): array
    {
        return $this->request('POST', '/rules/'.rawurlencode($key).'/versions', $payload);
    }

    /** @return array<string, mixed> */
    public function publishRuleVersion(string $key, int $version): array
    {
        if ($version < 1) {
            throw new \InvalidArgumentException('A rule version must be positive.');
        }

        return $this->request(
            'POST',
            '/rules/'.rawurlencode($key).'/versions/'.$version.'/publish',
        );
    }

    /**
     * Call any operation in the tenant OpenAPI contract by its operationId.
     *
     * Arguments are passed in named groups: `path`, `query`, `body`, `headers`,
     * and `idempotencyKey`. This low-level entry point also preserves CSV/PDF
     * responses through ApiResponse::body().
     *
     * @param  array{path?: array<string, scalar>, query?: array<string, scalar|null>, body?: array<string, mixed>, headers?: array<string, string>, idempotencyKey?: string}  $arguments
     */
    public function requestOperation(OperationId|string $operationId, array $arguments = []): ApiResponse
    {
        $operationId = $operationId instanceof OperationId ? $operationId->value : $operationId;
        $operation = self::operations()[$operationId] ?? null;
        if ($operation === null) {
            throw new \InvalidArgumentException(sprintf('Unknown tenant API operation: %s.', $operationId));
        }

        $pathParameters = $arguments['path'] ?? [];
        $query = $arguments['query'] ?? [];
        $body = $arguments['body'] ?? null;
        $headers = $arguments['headers'] ?? [];
        $idempotencyKey = $arguments['idempotencyKey'] ?? null;

        foreach ($operation['parameters'] as $parameter) {
            $location = $parameter['in'];
            $value = match ($location) {
                'path' => $pathParameters[$parameter['name']] ?? null,
                'query' => $query[$parameter['name']] ?? null,
                'header' => $parameter['name'] === 'Idempotency-Key'
                    ? $idempotencyKey
                    : ($headers[$parameter['name']] ?? null),
                default => null,
            };
            if ($parameter['required'] && ($value === null || $value === '')) {
                throw new \InvalidArgumentException(sprintf('Missing required %s parameter: %s.', $location, $parameter['name']));
            }
        }

        if ($operation['requestBodyRequired'] && $body === null) {
            throw new \InvalidArgumentException(sprintf('Operation %s requires a request body.', $operationId));
        }

        if ($operation['idempotencyRequired']) {
            $idempotencyKey = trim((string) $idempotencyKey);
            if (strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 255) {
                throw new \InvalidArgumentException('An idempotency key must be between 8 and 255 characters.');
            }
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $path = preg_replace_callback(
            '/\{([^}]+)\}/',
            static function (array $match) use ($pathParameters): string {
                if (! array_key_exists($match[1], $pathParameters)) {
                    throw new \InvalidArgumentException(sprintf('Missing required path parameter: %s.', $match[1]));
                }

                return rawurlencode((string) $pathParameters[$match[1]]);
            },
            $operation['path'],
        );

        $url = rtrim($this->baseUrl, '/').'/'.ltrim((string) $path, '/');
        if ($query !== []) {
            $url .= '?'.self::encodeQuery($query);
        }

        $headers = [
            'Accept' => implode(', ', $operation['responseContentTypes']) ?: 'application/json',
            ...$headers,
        ];
        $acceptsApiKey = self::acceptsApiKey($operation['security']);
        $acceptsSession = self::acceptsCustomerSession($operation['security']);
        if ($operation['security'] !== []
            && ! ($acceptsApiKey && $this->apiKey !== null)
            && ! ($acceptsSession && $this->sessionCookie !== null)) {
            throw new \InvalidArgumentException(sprintf('Operation %s requires an API key or customer session.', $operationId));
        }
        if ($operation['portalOriginRequired'] && $this->sessionOrigin === null) {
            throw new \InvalidArgumentException(sprintf('Operation %s requires a configured portal origin.', $operationId));
        }
        if ($operation['portalOriginRequired']) {
            $headers['Origin'] = $this->sessionOrigin;
        }
        if ($acceptsApiKey && $this->apiKey !== null) {
            $headers['Authorization'] = 'Bearer '.$this->apiKey;
        } elseif ($acceptsSession) {
            if ($this->sessionCookie === null) {
                throw new \InvalidArgumentException('Sign in with openSession before calling this operation.');
            }
            if ($operation['sessionOriginRequired'] && $this->sessionOrigin === null) {
                throw new \InvalidArgumentException('State-changing customer-session operations require a configured portal origin.');
            }
            $headers['Cookie'] = $this->sessionCookie;
            $headers['Trame-Environment'] = $this->sessionEnvironment;
            if ($operation['sessionOriginRequired']) {
                $headers['Origin'] = $this->sessionOrigin;
            }
        }
        if ($body !== null) {
            $headers['Content-Type'] = $operation['requestContentTypes'][0] ?? 'application/json';
        }

        try {
            $response = $this->httpClient->send(
                new Request(
                    $operation['method'],
                    $url,
                    $headers,
                    $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
                ),
                ['http_errors' => false, 'allow_redirects' => false, 'timeout' => $this->timeout],
            );
        } catch (GuzzleException|\JsonException $exception) {
            throw new ApiException(
                'The request could not be completed.',
                0,
                'transport_error',
                'transport_error',
                true,
                '',
                ['previous' => $exception->getMessage()],
            );
        }

        $responseHeaders = [];
        foreach ($response->getHeaders() as $name => $values) {
            $responseHeaders[$name] = array_values($values);
        }
        $result = new ApiResponse(
            $response->getStatusCode(),
            $responseHeaders,
            (string) $response->getBody(),
        );
        if ($response->getStatusCode() >= 300 && $response->getStatusCode() < 400) {
            throw new ApiException(
                'The API refused a redirect.',
                $response->getStatusCode(),
                'internal_error',
                'redirect_refused',
                false,
                $result->header('X-Request-Id') ?? '',
            );
        }
        if ($response->getStatusCode() < 400 && $operationId === 'openSession' && $response->hasHeader('Set-Cookie')) {
            foreach ($response->getHeader('Set-Cookie') as $cookie) {
                if (preg_match('/(?:^|;\s*)__Host-trame_customer=([^;]+)/', $cookie, $match) === 1) {
                    $this->sessionCookie = '__Host-trame_customer='.$match[1];
                    break;
                }
            }
        }
        if ($response->getStatusCode() >= 400) {
            $this->throwOperationError($result);
        }
        if ($operationId === 'closeSession') {
            $this->sessionCookie = null;
        }

        return $result;
    }

    /** @param list<mixed> $arguments */
    public function __call(string $operationId, array $arguments): ApiResponse
    {
        return $this->requestOperation($operationId, $arguments[0] ?? []);
    }

    /**
     * Yield all records from a cursor-paginated list operation.
     *
     * @param  array<string, mixed>  $arguments
     * @return \Generator<int, mixed>
     */
    public function paginate(string $operationId, array $arguments = []): \Generator
    {
        $query = $arguments['query'] ?? [];
        do {
            $arguments['query'] = $query;
            $response = $this->requestOperation($operationId, $arguments);
            $page = $response->json();
            if (! is_array($page)) {
                return;
            }
            foreach (($page['data'] ?? []) as $item) {
                yield $item;
            }
            $hasMore = ($page['has_more'] ?? false) === true;
            $nextCursor = $page['next_cursor'] ?? null;
            if (! $hasMore || ! is_string($nextCursor) || $nextCursor === '') {
                return;
            }
            $query['starting_after'] = $nextCursor;
        } while (true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload, string $idempotencyKey): array
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw new \InvalidArgumentException('An idempotency key is required for this operation.');
        }
        if (strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 255) {
            throw new \InvalidArgumentException('An idempotency key must be between 8 and 255 characters.');
        }

        return $this->request('POST', $path, $payload, $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        array $payload = [],
        ?string $idempotencyKey = null,
        array $query = [],
    ): array {
        if ($this->apiKey === null) {
            throw new \InvalidArgumentException('This operation requires an API key.');
        }

        $url = rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
        if ($query !== []) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        try {
            $headers = [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$this->apiKey,
            ];
            if ($payload !== []) {
                $headers['Content-Type'] = 'application/json';
            }
            if ($idempotencyKey !== null) {
                $headers['Idempotency-Key'] = $idempotencyKey;
            }

            $response = $this->httpClient->send(
                new Request(
                    $method,
                    $url,
                    $headers,
                    $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR),
                ),
                ['http_errors' => false, 'allow_redirects' => false, 'timeout' => $this->timeout],
            );
        } catch (GuzzleException|\JsonException $exception) {
            throw new ApiException(
                'The request could not be completed.',
                0,
                'transport_error',
                'transport_error',
                true,
                '',
                ['previous' => $exception->getMessage()],
            );
        }

        return $this->decodeResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }

        if (! is_array($decoded)) {
            throw new ApiException(
                'The API returned an invalid response.',
                $response->getStatusCode(),
                'internal_error',
                'invalid_json_response',
                true,
                $response->getHeaderLine('X-Request-Id'),
            );
        }

        if ($response->getStatusCode() >= 400) {
            $error = $decoded['error'] ?? null;
            if (! is_array($error)) {
                throw new ApiException(
                    'The API returned an invalid error response.',
                    $response->getStatusCode(),
                    'internal_error',
                    'invalid_error_response',
                    true,
                    $response->getHeaderLine('X-Request-Id'),
                );
            }

            throw new ApiException(
                (string) ($error['message'] ?? 'The API request failed.'),
                $response->getStatusCode(),
                (string) ($error['type'] ?? 'internal_error'),
                (string) ($error['code'] ?? 'unknown_error'),
                (bool) ($error['retryable'] ?? false),
                (string) ($error['request_id'] ?? $response->getHeaderLine('X-Request-Id')),
                is_array($error['details'] ?? null) ? $error['details'] : [],
            );
        }

        return $decoded;
    }

    /** @return array<string, array<string, mixed>> */
    private static function operations(): array
    {
        if (self::$operations !== null) {
            return self::$operations;
        }

        $manifest = json_decode(
            (string) file_get_contents(__DIR__.'/operations.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operations = is_array($manifest) ? ($manifest['operations'] ?? null) : null;
        if (! is_array($operations)) {
            throw new \UnexpectedValueException('The generated tenant API operation manifest is invalid.');
        }
        self::$operations = $operations;

        return $operations;
    }

    /** @param list<array<string, list<string>>> $security */
    private static function acceptsApiKey(array $security): bool
    {
        foreach ($security as $alternative) {
            if (array_key_exists('apiKey', $alternative)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $query */
    private static function encodeQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            if ($value === null) {
                continue;
            }
            foreach (is_array($value) ? $value : [$value] as $item) {
                if (is_bool($item)) {
                    $item = $item ? 'true' : 'false';
                }
                $pairs[] = rawurlencode((string) $name).'='.rawurlencode((string) $item);
            }
        }

        return implode('&', $pairs);
    }

    /** @param list<array<string, list<string>>> $security */
    private static function acceptsCustomerSession(array $security): bool
    {
        foreach ($security as $alternative) {
            if (array_key_exists('customerSession', $alternative)) {
                return true;
            }
        }

        return false;
    }

    private function throwOperationError(ApiResponse $response): never
    {
        $decoded = $response->json();
        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;
        if (! is_array($error)) {
            throw new ApiException(
                'The API returned an invalid error response.',
                $response->statusCode(),
                'internal_error',
                'invalid_error_response',
                true,
                $response->header('X-Request-Id') ?? '',
            );
        }

        throw new ApiException(
            (string) ($error['message'] ?? 'The API request failed.'),
            $response->statusCode(),
            (string) ($error['type'] ?? 'internal_error'),
            (string) ($error['code'] ?? 'unknown_error'),
            (bool) ($error['retryable'] ?? false),
            (string) ($error['request_id'] ?? $response->header('X-Request-Id') ?? ''),
            is_array($error['details'] ?? null) ? $error['details'] : [],
        );
    }
}
