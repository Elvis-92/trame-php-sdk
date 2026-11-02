<?php

declare(strict_types=1);

namespace Trame;

use InvalidArgumentException;

final readonly class PaymentDeclaration
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
        foreach (['amount', 'currency', 'country', 'provider', 'provider_reference'] as $required) {
            if (! array_key_exists($required, $attributes)) {
                throw new InvalidArgumentException("Payment declaration requires {$required}.");
            }
        }

        if (! is_int($attributes['amount']) || $attributes['amount'] < 1) {
            throw new InvalidArgumentException('Payment amount must be a positive integer.');
        }

        foreach (['currency' => 3, 'country' => 2] as $field => $length) {
            if (! is_string($attributes[$field]) || ! preg_match('/^[A-Z]{'.$length.'}$/', $attributes[$field])) {
                throw new InvalidArgumentException("Payment {$field} must use its uppercase ISO code.");
            }
        }

        if (! is_string($attributes['provider']) || $attributes['provider'] === '') {
            throw new InvalidArgumentException('Payment provider must be a non-empty string.');
        }

        if (! is_string($attributes['provider_reference']) || $attributes['provider_reference'] === '') {
            throw new InvalidArgumentException('Provider reference must be a non-empty string.');
        }
        if (strlen($attributes['provider_reference']) > 128) {
            throw new InvalidArgumentException('Provider reference cannot exceed 128 characters.');
        }

        $allocationModes = [
            array_key_exists('beneficiary_id', $attributes),
            array_key_exists('split', $attributes),
            array_key_exists('rule_key', $attributes) || array_key_exists('roles', $attributes),
        ];
        if (count(array_filter($allocationModes)) !== 1) {
            throw new InvalidArgumentException('Payment declaration requires exactly one allocation mode.');
        }

        if (array_key_exists('beneficiary_id', $attributes)
            && (! is_string($attributes['beneficiary_id']) || $attributes['beneficiary_id'] === '')) {
            throw new InvalidArgumentException('Beneficiary id must be a non-empty string.');
        }

        if (array_key_exists('split', $attributes)
            && (! is_array($attributes['split']) || $attributes['split'] === [])) {
            throw new InvalidArgumentException('Payment split must contain at least one allocation.');
        }

        $hasRuleKey = array_key_exists('rule_key', $attributes);
        $hasRoles = array_key_exists('roles', $attributes);
        if ($hasRuleKey !== $hasRoles) {
            throw new InvalidArgumentException('Rule allocations require both rule_key and roles.');
        }
        if ($hasRuleKey
            && (! is_string($attributes['rule_key']) || ! preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $attributes['rule_key']))) {
            throw new InvalidArgumentException('Rule key has an invalid format.');
        }
        if ($hasRoles && ! is_array($attributes['roles'])) {
            throw new InvalidArgumentException('Rule allocations require roles.');
        }

        if (array_key_exists('provider_fee', $attributes)
            && (! is_int($attributes['provider_fee']) || $attributes['provider_fee'] < 0)) {
            throw new InvalidArgumentException('Provider fee must be a non-negative integer.');
        }

        if (array_key_exists('occurred_at', $attributes)) {
            $occurredAt = $attributes['occurred_at'];
            $normalizedOccurredAt = is_string($occurredAt) ? str_replace('Z', '+00:00', $occurredAt) : '';
            $hasFraction = is_string($occurredAt) && str_contains($occurredAt, '.');
            $parsedOccurredAt = is_string($occurredAt)
                ? \DateTimeImmutable::createFromFormat(
                    $hasFraction ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP',
                    $normalizedOccurredAt,
                )
                : false;
            $parseErrors = \DateTimeImmutable::getLastErrors();
            $isValidDateTime = is_string($occurredAt)
                && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $occurredAt) === 1
                && $parsedOccurredAt !== false
                && ($parseErrors === false || ($parseErrors['warning_count'] === 0 && $parseErrors['error_count'] === 0))
                && $parsedOccurredAt->format('Y-m-d\TH:i:s') === substr($occurredAt, 0, 19)
                && $parsedOccurredAt->getTimestamp() <= time() + 300;
            if (! $isValidDateTime) {
                throw new InvalidArgumentException('Occurred at must be a valid date-time no more than five minutes in the future.');
            }
        }

        return new self($attributes);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->attributes;
    }
}
