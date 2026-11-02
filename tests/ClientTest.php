<?php

declare(strict_types=1);

namespace Trame\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Trame\ApiException;
use Trame\Client;
use Trame\EventCreate;
use Trame\PaymentDeclaration;

final class ClientTest extends TestCase
{
    public function test_declare_payment_sends_bearer_auth_and_idempotency(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(201, ['Content-Type' => 'application/json'], '{"id":"pay_1","status":"succeeded"}'),
        ]));
        $handler->push(Middleware::history($history));

        $client = new Client(
            'tr_test_key',
            new GuzzleClient(['handler' => $handler]),
        );

        self::assertSame(
            ['id' => 'pay_1', 'status' => 'succeeded'],
            $client->declarePayment(['amount' => 1000], 'order-001'),
        );

        self::assertSame('Bearer tr_test_key', $history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame('order-001', $history[0]['request']->getHeaderLine('Idempotency-Key'));
        self::assertSame('/v1/payments/declared', $history[0]['request']->getUri()->getPath());
    }

    public function test_money_operation_requires_an_idempotency_key(): void
    {
        $client = new Client('tr_test_key', new GuzzleClient);

        $this->expectException(InvalidArgumentException::class);
        $client->declarePayment(['amount' => 1000], '');
    }

    public function test_api_error_preserves_machine_readable_retryability(): void
    {
        $handler = new MockHandler([
            new Response(
                502,
                ['X-Request-Id' => 'req_header'],
                json_encode([
                    'error' => [
                        'type' => 'provider_state_unknown',
                        'code' => 'provider_state_unknown',
                        'message' => 'Poll the operation.',
                        'retryable' => false,
                        'request_id' => 'req_body',
                        'details' => ['operation_id' => 'op_1'],
                    ]], JSON_THROW_ON_ERROR),
            ),
        ]);

        $client = new Client('tr_test_key', new GuzzleClient(['handler' => $handler]));

        try {
            $client->declarePayment(['amount' => 1000], 'order-001');
            self::fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            self::assertSame('provider_state_unknown', $exception->errorCode());
            self::assertFalse($exception->retryable());
            self::assertSame('req_body', $exception->requestId());
            self::assertSame(['operation_id' => 'op_1'], $exception->details());
        }
    }

    public function test_http_errors_are_decoded_as_api_errors(): void
    {
        $handler = new MockHandler([
            new Response(
                422,
                ['X-Request-Id' => 'req_422'],
                '{"error":{"type":"unprocessable_state","code":"beneficiary_unverified","message":"Verify first.","retryable":false,"request_id":"req_422"}}',
            ),
        ]);

        $client = new Client('tr_test_key', new GuzzleClient(['handler' => $handler]));

        $this->expectException(ApiException::class);
        try {
            $client->createBeneficiary(['external_ref' => 'courier-1']);
        } catch (ApiException $exception) {
            self::assertSame('beneficiary_unverified', $exception->errorCode());
            self::assertSame(422, $exception->httpStatus());
            throw $exception;
        }
    }

    public function test_beneficiary_and_rule_methods_use_contract_paths(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(201, [], '{"id":"ben_1"}'),
            new Response(200, [], '{"object":"list","data":[]}'),
            new Response(200, [], '{"id":"ben_1"}'),
            new Response(201, [], '{"key":"standard"}'),
            new Response(200, [], '{"object":"list","data":[]}'),
            new Response(200, [], '{"key":"standard"}'),
            new Response(201, [], '{"version":1}'),
            new Response(200, [], '{"version":1,"published":true}'),
        ]));
        $handler->push(Middleware::history($history));
        $client = new Client('tr_test_key', new GuzzleClient(['handler' => $handler]));

        $client->createBeneficiary(['external_ref' => 'courier-1']);
        $client->listBeneficiaries(['limit' => 10, 'starting_after' => 'cursor/1']);
        $client->renameBeneficiary('ben/1', ['display_name' => 'Courier']);
        $client->createRule('standard');
        $client->listRules();
        $client->getRule('standard');
        $client->createRuleVersion('standard', ['allocations' => []]);
        $client->publishRuleVersion('standard', 1);

        self::assertSame('/v1/beneficiaries', $history[0]['request']->getUri()->getPath());
        self::assertSame('/v1/beneficiaries', $history[1]['request']->getUri()->getPath());
        self::assertSame('limit=10&starting_after=cursor%2F1', $history[1]['request']->getUri()->getQuery());
        self::assertSame('/v1/beneficiaries/ben%2F1', $history[2]['request']->getUri()->getPath());
        self::assertSame('/v1/rules/standard', $history[5]['request']->getUri()->getPath());
        self::assertSame('/v1/rules/standard/versions/1/publish', $history[7]['request']->getUri()->getPath());
    }

    public function test_api_key_must_use_a_trame_prefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Client('secret', new GuzzleClient);
    }

    public function test_key_environment_must_match_api_host(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Client('tr_live_key', new GuzzleClient);
    }

    public function test_key_environment_requires_https_without_custom_port_or_credentials(): void
    {
        $httpClient = new GuzzleClient;

        foreach ([
            'http://api.trame.africa/v1',
            'https://api.trame.africa:8443/v1',
            'https://user:pass@api.trame.africa/v1',
            'https://api.trame.africa/v1?redirect=unsafe',
            'https://api.trame.africa/v1#unsafe',
        ] as $baseUrl) {
            try {
                new Client('tr_live_key', $httpClient, $baseUrl);
                self::fail('Expected an invalid base URL exception.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_typed_payment_and_event_requests_validate_before_http(): void
    {
        $payment = PaymentDeclaration::fromArray([
            'amount' => 1000,
            'currency' => 'XOF',
            'country' => 'BJ',
            'provider' => 'fedapay',
            'provider_reference' => 'fp_1',
            'beneficiary_id' => 'ben_1',
        ]);
        $event = EventCreate::fromArray([
            'type' => 'service.completed',
            'payment_id' => '00000000-0000-4000-8000-000000000001',
            'payload' => ['source' => 'platform'],
        ]);

        self::assertSame('XOF', $payment->toArray()['currency']);
        self::assertSame('service.completed', $event->toArray()['type']);

        $this->expectException(InvalidArgumentException::class);
        PaymentDeclaration::fromArray([
            'amount' => 10.5,
            'currency' => 'XOF',
            'country' => 'BJ',
            'provider' => 'fedapay',
            'provider_reference' => 'fp_1',
            'beneficiary_id' => 'ben_1',
        ]);
    }

    public function test_typed_payment_rejects_incomplete_rule_allocation_and_invalid_occurred_at(): void
    {
        $base = [
            'amount' => 1000,
            'currency' => 'XOF',
            'country' => 'BJ',
            'provider' => 'fedapay',
            'provider_reference' => 'fp_1',
        ];

        foreach ([
            ['roles' => []],
            ['rule_key' => 'standard'],
            ['rule_key' => 'standard', 'roles' => [], 'occurred_at' => '2026-01-01T12:00:00'],
            ['rule_key' => 'standard', 'roles' => [], 'occurred_at' => '2099-01-01T12:00:00Z'],
            ['rule_key' => 'standard', 'roles' => [], 'occurred_at' => '2026-02-30T12:00:00Z'],
        ] as $invalid) {
            try {
                PaymentDeclaration::fromArray([...$base, ...$invalid]);
                self::fail('Expected an invalid payment declaration exception.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_payment_lifecycle_methods_use_idempotency_for_writes(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"id":"pay/1"}'),
            new Response(200, [], '{"id":"pay/1","released":true}'),
            new Response(200, [], '{"id":"pay/1","status":"succeeded"}'),
            new Response(201, [], '{"id":"ref_1"}'),
            new Response(201, [], '{"id":"ref_2"}'),
        ]));
        $handler->push(Middleware::history($history));
        $client = new Client('tr_test_key', new GuzzleClient(['handler' => $handler]));

        $client->getPayment('pay/1');
        $client->releasePayment('pay/1', 'release-1');
        $client->confirmPayment('pay/1', 'confirm-1');
        $client->refundPayment('pay/1', ['amount' => 100, 'currency' => 'XOF'], 'refund-1');
        $client->declareRefund('pay/1', ['amount' => 100, 'currency' => 'XOF', 'provider_reference' => 'ref'], 'refund-2');

        self::assertSame('/v1/payments/pay%2F1', $history[0]['request']->getUri()->getPath());
        self::assertSame('release-1', $history[1]['request']->getHeaderLine('Idempotency-Key'));
        self::assertSame('confirm-1', $history[2]['request']->getHeaderLine('Idempotency-Key'));
        self::assertSame('/v1/payments/pay%2F1/refunds', $history[3]['request']->getUri()->getPath());
        self::assertSame('/v1/payments/pay%2F1/refunds/declared', $history[4]['request']->getUri()->getPath());
    }
}
