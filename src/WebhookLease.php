<?php

declare(strict_types=1);

namespace Trame;

final readonly class WebhookLease
{
    public function __construct(
        public WebhookDeliveryStatus $status,
        public string $token,
    ) {}
}
