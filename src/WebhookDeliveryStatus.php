<?php

declare(strict_types=1);

namespace Trame;

enum WebhookDeliveryStatus: string
{
    case Process = 'process';
    case InProgress = 'in_progress';
    case AlreadyProcessed = 'already_processed';
}
