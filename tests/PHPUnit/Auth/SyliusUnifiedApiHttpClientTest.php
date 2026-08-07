<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Auth;

use PayPlug\SyliusPayPlugPlugin\Auth\SyliusUnifiedApiHttpClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class SyliusUnifiedApiHttpClientTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;

    private SyliusUnifiedApiHttpClient $adapter;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->adapter = new SyliusUnifiedApiHttpClient($this->httpClient);
    }

    public function testGet_sendsRequestWithHeadersAndReturnsStatusAndBody(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->with(false)->willReturn('{"id":"pay_123"}');

        $this->httpClient->expects(self::once())
            ->method('request')
            ->with(
                'GET',
                'https://api.payplug.com/payments/pay_123',
                ['headers' => ['Authorization' => 'Bearer jwt']],
            )
            ->willReturn($response)
        ;

        $result = $this->adapter->get(
            'https://api.payplug.com/payments/pay_123',
            ['Authorization' => 'Bearer jwt'],
        );

        self::assertSame(['status' => 200, 'body' => '{"id":"pay_123"}'], $result);
    }

    public function testPostJson_sendsJsonBodyAndHeaders(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(201);
        $response->method('getContent')->with(false)->willReturn('{"id":"pay_123"}');

        $this->httpClient->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                'https://api.payplug.com/payments',
                [
                    'json' => ['amount' => 1000, 'currency' => 'EUR'],
                    'headers' => ['Authorization' => 'Bearer jwt', 'Content-Type' => 'application/json'],
                ],
            )
            ->willReturn($response)
        ;

        $result = $this->adapter->postJson(
            'https://api.payplug.com/payments',
            ['amount' => 1000, 'currency' => 'EUR'],
            ['Authorization' => 'Bearer jwt', 'Content-Type' => 'application/json'],
        );

        self::assertSame(['status' => 201, 'body' => '{"id":"pay_123"}'], $result);
    }

    public function testPostJson_onNon2xxStatus_returnsStatusAndBodyWithoutThrowing(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(401);
        $response->method('getContent')->with(false)->willReturn('{"error":"unauthorized"}');

        $this->httpClient->method('request')->willReturn($response);

        $result = $this->adapter->postJson('https://api.payplug.com/payments', ['amount' => 1000]);

        self::assertSame(['status' => 401, 'body' => '{"error":"unauthorized"}'], $result);
    }

    public function testGet_onTransportFailure_returnsZeroStatusInsteadOfThrowing(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willThrowException(new TransportException('Could not resolve host'));

        $this->httpClient->method('request')->willReturn($response);

        $result = $this->adapter->get('https://api.payplug.com/payments/pay_123');

        self::assertSame(0, $result['status']);
        self::assertSame('Could not resolve host', $result['body']);
    }
}
