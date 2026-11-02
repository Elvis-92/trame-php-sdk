<?php

declare(strict_types=1);

namespace Trame;

use InvalidArgumentException;

final readonly class EventCreate
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function __construct(private array $attributes) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        foreach (['type', 'payment_id'] as $required) {
            if (! array_key_exists($required, $attributes)) {
                throw new InvalidArgumentException("Event requires {$required}.");
            }
            if (! is_string($attributes[$required]) || $attributes[$required] === '') {
                throw new InvalidArgumentException("Event {$required} must be a non-empty string.");
            }
        }
        if (strlen((string) $attributes['type']) > 64) {
            throw new InvalidArgumentException('Event type cannot exceed 64 characters.');
        }
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string) $attributes['payment_id'])) {
            throw new InvalidArgumentException('Event payment id must be a UUID.');
        }

        return new self($attributes);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->attributes;
    }
}
