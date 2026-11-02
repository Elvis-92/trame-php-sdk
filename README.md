# Trame PHP SDK

PHP client for the versioned Trame API.

This package is intentionally explicit: it does not replace a platform's
payment-provider SDK and does not collect business events automatically. A
platform keeps its existing payment flow, then declares the operation to Trame
with the provider's reference.

## Installation

The package is distributed from the
[`trame-php-sdk`](https://github.com/Elvis-92/trame-php-sdk) repository. Once
it is listed on Packagist, install it with:

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

## Call the complete tenant API

The OpenAPI `operationId` is the stable name for each documented operation.
Use `requestOperation()` for operations that do not yet have a dedicated
convenience method:

```php
$page = $trame->requestOperation('listPayments', [
    'query' => ['limit' => 50],
]);

foreach ($trame->paginate('listPayments', ['query' => ['limit' => 50]]) as $payment) {
    // Process one payment from each cursor page.
}

$statement = $trame->requestOperation('downloadJournal', [
    'path' => ['period' => '2026-10'],
]);
$csv = $statement->body();
```

`requestOperation()` returns an `ApiResponse` with the status, headers, and raw
body. Call `json()` for JSON; use `body()` and `header()` for CSV and PDF
responses. Supply path and query parameters under their corresponding keys,
request data as `body`, and an `idempotencyKey` when the OpenAPI contract
requires one. Customer-session operations use the session cookie established
by `openSession`; configure the matching `sessionEnvironment` and, for writes,
the allowed portal `sessionOrigin`.

The operation manifest is generated from `docs/03-api/openapi.yaml` with
`python3 tools/sdk/generate_operation_manifests.py` after installing the
development requirement in `tools/sdk/requirements.txt`. PHPStan array-shape
aliases for the schemas are generated with
`python3 tools/sdk/generate_schema_models.py`; import them from
`\Trame\Models`, for example with
`@phpstan-import-type TrameApiPaymentDeclaration from \Trame\Models`. These provide
static analysis only and do not perform runtime validation.

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
