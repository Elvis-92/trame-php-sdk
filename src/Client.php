<?php

declare(strict_types=1);

namespace Trame;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

final class Client
{
    public const DEFAULT_BASE_URL = 'https://api.preprod.trame.africa/v1';

    public const LIVE_BASE_URL = 'https://api.trame.africa/v1';

    public function __construct(
        private readonly string $apiKey,
        private readonly ClientInterface $httpClient,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('An API key is required.');
        }

        if (! str_starts_with($apiKey, 'tr_test_') && ! str_starts_with($apiKey, 'tr_live_')) {
            throw new \InvalidArgumentException('The API key must start with tr_test_ or tr_live_.');
        }

        $host = parse_url($baseUrl, PHP_URL_HOST);
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        $port = parse_url($baseUrl, PHP_URL_PORT);
        $user = parse_url($baseUrl, PHP_URL_USER);
        $password = parse_url($baseUrl, PHP_URL_PASS);
        $query = parse_url($baseUrl, PHP_URL_QUERY);
        $fragment = parse_url($baseUrl, PHP_URL_FRAGMENT);
        $expectedHost = str_starts_with($apiKey, 'tr_live_')
            ? 'api.trame.africa'
            : 'api.preprod.trame.africa';
        if ($scheme !== 'https'
            || $port !== null
            || $user !== null
            || $password !== null
            || $query !== null
            || $fragment !== null
            || $host !== $expectedHost) {
            throw new \InvalidArgumentException(
                sprintf('The %s key must use %s.', str_starts_with($apiKey, 'tr_live_') ? 'live' : 'test', $expectedHost),
            );
        }
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

    public function getPayment(string $paymentId): array
    {
        return $this->request('GET', '/payments/'.rawurlencode($paymentId));
    }

    public function releasePayment(string $paymentId, string $idempotencyKey): array
    {
        return $this->post('/payments/'.rawurlencode($paymentId).'/release', [], $idempotencyKey);
    }

    public function confirmPayment(string $paymentId, string $idempotencyKey): array
    {
        return $this->post('/payments/'.rawurlencode($paymentId).'/confirm', [], $idempotencyKey);
    }

    /** @param array<string, mixed> $payload */
    public function refundPayment(string $paymentId, array $payload, string $idempotencyKey): array
    {
        return $this->post('/payments/'.rawurlencode($paymentId).'/refunds', $payload, $idempotencyKey);
    }

    /** @param array<string, mixed> $payload */
    public function declareRefund(string $paymentId, array $payload, string $idempotencyKey): array
    {
        return $this->post('/payments/'.rawurlencode($paymentId).'/refunds/declared', $payload, $idempotencyKey);
    }

    /** @param array<string, mixed> $payload */
    public function createBeneficiary(array $payload): array
    {
        return $this->request('POST', '/beneficiaries', $payload);
    }

    /** @param array<string, scalar|null> $query */
    public function listBeneficiaries(array $query = []): array
    {
        return $this->request('GET', '/beneficiaries', query: $query);
    }

    /** @param array<string, mixed> $payload */
    public function renameBeneficiary(string $beneficiaryId, array $payload): array
    {
        return $this->request('PATCH', '/beneficiaries/'.rawurlencode($beneficiaryId), $payload);
    }

    /** @param array<string, mixed> $payload */
    public function createRule(string $key): array
    {
        return $this->request('POST', '/rules', ['key' => $key]);
    }

    public function listRules(): array
    {
        return $this->request('GET', '/rules');
    }

    public function getRule(string $key): array
    {
        return $this->request('GET', '/rules/'.rawurlencode($key));
    }

    /** @param array<string, mixed> $payload */
    public function createRuleVersion(string $key, array $payload): array
    {
        return $this->request('POST', '/rules/'.rawurlencode($key).'/versions', $payload);
    }

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
                ['http_errors' => false],
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
}
