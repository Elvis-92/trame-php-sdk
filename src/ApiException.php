<?php

declare(strict_types=1);

namespace Trame;

use RuntimeException;

final class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        private readonly int $httpStatus,
        private readonly string $type,
        private readonly string $errorCode,
        private readonly bool $retryable,
        private readonly string $requestId,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
