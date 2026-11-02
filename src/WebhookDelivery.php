<?php

declare(strict_types=1);

namespace Trame;

final class WebhookDelivery
{
    /**
     * @param  array<string, mixed>  $event
     */
    public function __construct(
        private readonly array $event,
        private readonly string $rawPayload,
        private readonly WebhookEventStore $store,
        private readonly WebhookLease $lease,
    ) {}

    /** @return array<string, mixed> */
    public function event(): array
    {
        return $this->event;
    }

    public function rawPayload(): string
    {
        return $this->rawPayload;
    }

    public function shouldProcess(): bool
    {
        return $this->lease->status === WebhookDeliveryStatus::Process;
    }

    public function acknowledge(): void
    {
        if ($this->shouldProcess()) {
            $this->store->acknowledge((string) $this->event['id'], $this->lease->token);
        }
    }
}
