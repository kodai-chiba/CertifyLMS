<?php

declare(strict_types=1);

namespace Tests\Support;

use Mockery;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

trait PreventsStripeApiRequests
{
    private ?ClientInterface $originalStripeHttpClient = null;

    protected function preventStripeApiRequests(): void
    {
        $this->originalStripeHttpClient = ApiRequestor::httpClient();

        $httpClient = Mockery::mock(ClientInterface::class);

        $httpClient->shouldNotReceive('request')
            ->andThrow(
                new RuntimeException(
                    'テスト中のStripe APIへの外部通信は禁止されています。'
                )
            );

        ApiRequestor::setHttpClient($httpClient);
    }

    protected function restoreStripeHttpClient(): void
    {
        if ($this->originalStripeHttpClient === null) {
            return;
        }

        ApiRequestor::setHttpClient(
            $this->originalStripeHttpClient
        );

        $this->originalStripeHttpClient = null;
    }
}
