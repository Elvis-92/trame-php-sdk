<?php

declare(strict_types=1);

namespace Trame\Tests;

use PHPUnit\Framework\TestCase;
use Trame\WebhookDeliveryStatus;
use Trame\WebhookEventStore;
use Trame\WebhookLease;
use Trame\WebhookVerificationException;
use Trame\WebhookVerifier;

final class WebhookVerifierTest extends TestCase
{
    private const SECRET = 'whsec_test';

    private const NOW = 1_788_400_000;

    private function eventStore(): WebhookEventStore
    {
        return new class implements WebhookEventStore
        {
            /** @var array<string, bool> */
            private array $processed = [];

            public function start(string $eventId, string $payload): WebhookLease
            {
                $status = ($this->processed[$eventId] ?? false)
                    ? WebhookDeliveryStatus::AlreadyProcessed
                    : WebhookDeliveryStatus::Process;

                return new WebhookLease($status, 'lease-'.$eventId);
            }

            public function acknowledge(string $eventId, string $leaseToken): void
            {
                $this->processed[$eventId] = true;
            }
        };
    }

    public function test_it_verifies_the_signed_raw_body(): void
    {
        $body = '{"id":"evt_1","type":"payment.succeeded"}';
        $signature = 't='.self::NOW.',v1='.hash_hmac('sha256', self::NOW.'.'.$body, self::SECRET);

        self::assertSame(
            ['id' => 'evt_1', 'type' => 'payment.succeeded'],
            WebhookVerifier::verifyAndParse($body, $signature, self::SECRET, self::NOW),
        );
    }

    public function test_it_rejects_a_changed_body(): void
    {
        $body = '{"amount":100000}';
        $signature = 't='.self::NOW.',v1='.hash_hmac('sha256', self::NOW.'.'.$body, self::SECRET);

        $this->expectException(WebhookVerificationException::class);
        WebhookVerifier::verifyAndParse('{"amount":900000}', $signature, self::SECRET, self::NOW);
    }

    public function test_it_rejects_an_expired_signature(): void
    {
        $body = '{"id":"evt_1"}';
        $signature = 't='.self::NOW.',v1='.hash_hmac('sha256', self::NOW.'.'.$body, self::SECRET);

        $this->expectException(WebhookVerificationException::class);
        WebhookVerifier::verifyAndParse($body, $signature, self::SECRET, self::NOW + 301);
    }

    public function test_it_rejects_malformed_headers(): void
    {
        $this->expectException(WebhookVerificationException::class);
        WebhookVerifier::verifyAndParse('{}', 'v1=abc', self::SECRET, self::NOW);
    }

    public function test_it_rejects_a_signed_json_array(): void
    {
        $body = '[{"id":"evt_1"}]';
        $signature = 't='.self::NOW.',v1='.hash_hmac('sha256', self::NOW.'.'.$body, self::SECRET);

        $this->expectException(WebhookVerificationException::class);
        WebhookVerifier::verifyAndParse($body, $signature, self::SECRET, self::NOW);
    }

    public function test_it_skips_an_already_acknowledged_event_but_allows_a_failed_delivery_to_retry(): void
    {
        $body = '{"id":"evt_1","type":"payment.succeeded"}';
        $signature = 't='.self::NOW.',v1='.hash_hmac('sha256', self::NOW.'.'.$body, self::SECRET);
        $store = $this->eventStore();

        $first = WebhookVerifier::verifyAndParseWithStore($body, $signature, self::SECRET, $store, self::NOW);
        self::assertTrue($first->shouldProcess());

        $retry = WebhookVerifier::verifyAndParseWithStore($body, $signature, self::SECRET, $store, self::NOW);
        self::assertTrue($retry->shouldProcess());
        $retry->acknowledge();

        $duplicate = WebhookVerifier::verifyAndParseWithStore($body, $signature, self::SECRET, $store, self::NOW);
        self::assertFalse($duplicate->shouldProcess());
    }

    public function test_an_in_progress_delivery_is_not_processed_concurrently(): void
    {
        $body = '{"id":"evt_1","type":"payment.succeeded"}';
        $signature = 't='.self::NOW.',v1='.hash_hmac('sha256', self::NOW.'.'.$body, self::SECRET);
        $store = new class implements WebhookEventStore
        {
            public function start(string $eventId, string $payload): WebhookLease
            {
                return new WebhookLease(WebhookDeliveryStatus::InProgress, 'lease-'.$eventId);
            }

            public function acknowledge(string $eventId, string $leaseToken): void {}
        };

        $delivery = WebhookVerifier::verifyAndParseWithStore($body, $signature, self::SECRET, $store, self::NOW);

        self::assertFalse($delivery->shouldProcess());
    }
}
