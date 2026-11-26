<?php

declare(strict_types=1);

namespace Tests\Unit;

use Mockery;
use Mockery\Exception\InvalidCountException;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Support\PreventsStripeApiRequests;
use Tests\TestCase;

/**
 * @group external-api
 * @group stripe
 */
class PreventsStripeApiRequestsTest extends TestCase
{
    use PreventsStripeApiRequests;

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $this->restoreStripeHttpClient();
        }
    }

    public function test_stripe_http_client_is_replaced(): void
    {
        $originalClient = ApiRequestor::httpClient();

        $this->preventStripeApiRequests();

        $mockClient = ApiRequestor::httpClient();

        $this->assertInstanceOf(
            ClientInterface::class,
            $mockClient
        );

        $this->assertNotSame(
            $originalClient,
            $mockClient
        );
    }

    public function test_unexpected_stripe_api_request_is_rejected(): void
    {
        $this->preventStripeApiRequests();

        try {
            ApiRequestor::httpClient()->request(
                'get',
                'https://api.stripe.com/v1/customers',
                [],
                [],
                false
            );

            $this->fail(
                'Stripe APIへの外部通信が拒否される必要があります。'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'テスト中のStripe APIへの外部通信は禁止されています。',
                $exception->getMessage()
            );
        } finally {
            $this->restoreStripeHttpClient();

            // 意図的な呼び出しによる回数違反を検証する。
            $this->expectException(InvalidCountException::class);

            Mockery::close();
        }
    }

    public function test_original_stripe_http_client_is_restored(): void
    {
        $originalClient = ApiRequestor::httpClient();

        $this->preventStripeApiRequests();

        $this->assertNotSame(
            $originalClient,
            ApiRequestor::httpClient()
        );

        $this->restoreStripeHttpClient();

        $this->assertSame(
            $originalClient,
            ApiRequestor::httpClient()
        );
    }
}
