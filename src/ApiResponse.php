<?php

declare(strict_types=1);

namespace Trame;

use JsonException;

final class ApiResponse
{
    /**
     * @param  array<string, list<string>>  $headers
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly array $headers,
        private readonly string $body,
    ) {}

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, list<string>> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $header => $values) {
            if (strcasecmp($header, $name) === 0) {
                return $values[0] ?? null;
            }
        }

        return null;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    public function json(): ?array
    {
        if ($this->body === '') {
            return null;
        }

        try {
            $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException(
                'The API response is not valid JSON.',
                $this->statusCode,
                'internal_error',
                'invalid_json_response',
                true,
                $this->header('X-Request-Id') ?? '',
            );
        }

        return is_array($decoded) ? $decoded : null;
    }
}
