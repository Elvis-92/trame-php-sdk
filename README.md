# Trame PHP SDK

PHP client for the versioned Trame API.

This package is intentionally explicit: it does not replace a platform's
payment-provider SDK and does not collect business events automatically. A
platform keeps its existing payment flow, then declares the operation to Trame
with the provider's reference.

## Installation

```bash
composer require trame/trame-php
```

## Declare a payment

```php
use GuzzleHttp\Client as GuzzleClient;
use Trame\Client;

$trame = new Client('tr_test_...', new GuzzleClient());

$payment = $trame->declarePayment([
    'amount' => 10000,
    'currency' => 'XOF',
    'country' => 'BJ',
    'provider' => 'fedapay',
    'provider_reference' => 'provider-transaction-id',
    'beneficiary_id' => 'beneficiary-id',
], 'platform-order-4821');
```

The second argument is required because Trame requires `Idempotency-Key` on
every operation that moves money.

For validation before sending, use the typed request object:

```php
use Trame\PaymentDeclaration;

$payment = $trame->declarePayment(
    PaymentDeclaration::fromArray([
        'amount' => 10000,
        'currency' => 'XOF',
        'country' => 'BJ',
        'provider' => 'fedapay',
        'provider_reference' => 'provider-transaction-id',
        'beneficiary_id' => 'beneficiary-id',
    ]),
    'platform-order-4821',
);
```

`PaymentDeclaration` rejects decimal amounts, lowercase currency/country codes,
missing provider references and empty provider names before a request is sent.
`EventCreate::fromArray()` provides the same explicit validation for platform
signals.

The client enforces environment isolation: `tr_test_` keys use
`api.preprod.trame.africa`, while `tr_live_` keys must use
`api.trame.africa`. A live key cannot be sent to pre-production by
misconfiguration.

## Verify a webhook

Pass the raw request body, the complete `Trame-Signature` header and the
endpoint secret before parsing or acting on the event:

```php
use Trame\WebhookVerifier;

$delivery = WebhookVerifier::verifyAndParseWithStore(
    file_get_contents('php://input'),
    $_SERVER['HTTP_TRAME_SIGNATURE'] ?? '',
    $_ENV['TRAME_WEBHOOK_SECRET'],
    $eventStore,
);

if ($delivery->shouldProcess()) {
    process($delivery->event());
    $delivery->acknowledge();
}
```

`verifyAndParse()` is a low-level authentication helper and does not
deduplicate deliveries; do not use it as a production webhook handler.
`verifyAndParseWithStore()` rejects malformed, altered and expired signatures
and rejects valid signatures whose body is not a JSON object. The event store must
persist the raw body atomically by event id, take a bounded processing lease,
and only acknowledge it after the application side effects succeed. The lease
must carry a fencing token, and `acknowledge()` must reject stale tokens. A
concurrent delivery receives `shouldProcess() === false`; an abandoned lease
must expire so a later retry can process the event. An already acknowledged
delivery is safely ignored. An in-memory store is not safe for production.

## Error handling

`ApiException` exposes `type()`, `errorCode()`, `retryable()`, `requestId()`
and `details()`. Callers must branch on `errorCode()`, not on the human message.
`provider_state_unknown` is not safe to retry; poll the operation instead.
