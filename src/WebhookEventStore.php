<?php

declare(strict_types=1);

namespace Trame;

interface WebhookEventStore
{
    /**
     * Records receipt and returns whether the event still needs processing.
     *
     * Implementations must persist the raw payload, use an atomic event-id
     * lookup and lease, and recover an abandoned lease after a bounded
     * timeout. A received-but-unacknowledged event must remain processable on a
     * later delivery.
     */
    public function start(string $eventId, string $payload): WebhookLease;

    /**
     * Marks the event processed only after all application side effects succeed.
     */
    public function acknowledge(string $eventId, string $leaseToken): void;
}
