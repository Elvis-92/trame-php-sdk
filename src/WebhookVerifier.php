<?php

declare(strict_types=1);

namespace Trame;

use JsonException;

final class WebhookVerifier
{
    /**
     * Authenticates and parses a webhook without delivery deduplication.
     *
     * @deprecated Use verifyAndParseWithStore() for production handlers.
     *
     * @return array<string, mixed>
     */
    public static function verifyAndParse(
        string $payload,
        string $signature,
        string $secret,
        ?int $now = null,
        int $tolerance = 300,
    ): array {
        if ($secret === '' || $tolerance < 0) {
            throw new WebhookVerificationException('Invalid webhook verification configuration.');
        }

        $parts = [];
        foreach (explode(',', $signature) as $component) {
            [$key, $value] = array_pad(explode('=', trim($component), 2), 2, null);
            if ($key === null || $value === null || $key === '' || $value === '') {
                throw new WebhookVerificationException('Malformed webhook signature.');
            }
            $parts[$key][] = $value;
        }

        $timestamps = $parts['t'] ?? [];
        $signatures = $parts['v1'] ?? [];
        if (count($timestamps) !== 1 || $signatures === []) {
            throw new WebhookVerificationException('Malformed webhook signature.');
        }

        $timestamp = $timestamps[0];
        if (! ctype_digit($timestamp)) {
            throw new WebhookVerificationException('Malformed webhook timestamp.');
        }

        $currentTime = $now ?? time();
        if (abs($currentTime - (int) $timestamp) > $tolerance) {
            throw new WebhookVerificationException('Webhook signature is outside the replay window.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $candidate) {
            if (ctype_xdigit($candidate) && hash_equals($expected, strtolower($candidate))) {
                try {
                    $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new WebhookVerificationException('Webhook payload must be valid JSON.');
                }

                if (! is_array($decoded) || array_is_list($decoded)) {
                    throw new WebhookVerificationException('Webhook payload must be a JSON object.');
                }

                return $decoded;
            }
        }

        throw new WebhookVerificationException('Webhook signature does not match the payload.');
    }

    public static function verifyAndParseWithStore(
        string $payload,
        string $signature,
        string $secret,
        WebhookEventStore $store,
        ?int $now = null,
        int $tolerance = 300,
    ): WebhookDelivery {
        $event = self::verifyAndParse($payload, $signature, $secret, $now, $tolerance);
        $eventId = $event['id'] ?? null;
        if (! is_string($eventId) || $eventId === '') {
            throw new WebhookVerificationException('Webhook payload must contain an event id.');
        }

        return new WebhookDelivery($event, $payload, $store, $store->start($eventId, $payload));
    }
}
