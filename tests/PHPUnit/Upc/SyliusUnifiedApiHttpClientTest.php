<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Upc;

use PayPlug\SyliusPayPlugPlugin\Upc\SyliusUnifiedApiHttpClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class SyliusUnifiedApiHttpClientTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;

    private LoggerInterface&MockObject $logger;

    private SyliusUnifiedApiHttpClient $adapter;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->adapter = new SyliusUnifiedApiHttpClient($this->httpClient, $this->logger, true);
    }

    public function testGet_sendsGetRequestWithHeaders(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->with(false)->willReturn('{"id":"pay_123"}');

        $this->httpClient->expects(self::once())->method('request')
            ->with('GET', 'https://api.payplug.com/payments/pay_123', [
                'headers' => ['Authorization' => 'Bearer jwt'],
                'timeout' => 10,
            ])
            ->willReturn($response);

        $result = $this->adapter->get('https://api.payplug.com/payments/pay_123', ['Authorization' => 'Bearer jwt']);

        self::assertSame(['status' => 200, 'body' => '{"id":"pay_123"}'], $result);
    }

    public function testPostJson_sendsJsonEncodedBody(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(201);
        $response->method('getContent')->with(false)->willReturn('{"id":"pay_123"}');

        $this->httpClient->expects(self::once())->method('request')
            ->with('POST', 'https://api.payplug.com/payments', [
                'json' => ['amount' => 1000],
                'headers' => ['Authorization' => 'Bearer jwt'],
                'timeout' => 10,
            ])
            ->willReturn($response);

        $result = $this->adapter->postJson('https://api.payplug.com/payments', ['amount' => 1000], ['Authorization' => 'Bearer jwt']);

        self::assertSame(['status' => 201, 'body' => '{"id":"pay_123"}'], $result);
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

    public function testPostJson_onTransportFailure_returnsZeroStatusInsteadOfThrowing(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willThrowException(new TransportException('Could not resolve host'));
        $this->httpClient->method('request')->willReturn($response);

        $result = $this->adapter->postJson('https://api.payplug.com/payments', []);

        self::assertSame(0, $result['status']);
        self::assertSame('Could not resolve host', $result['body']);
    }

    public function testGet_whenVerifyTlsDisabled_passesVerifyPeerAndVerifyHostFalse(): void
    {
        $adapter = new SyliusUnifiedApiHttpClient($this->httpClient, $this->logger, false);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->with(false)->willReturn('{}');

        $this->httpClient->expects(self::once())->method('request')
            ->with('GET', 'https://staging-internal-payment.gcp.dlns.io/processing-operations/operations/op_1', [
                'headers' => [],
                'verify_peer' => false,
                'verify_host' => false,
                'timeout' => 10,
            ])
            ->willReturn($response);

        $adapter->get('https://staging-internal-payment.gcp.dlns.io/processing-operations/operations/op_1');
    }

    public function testGet_whenVerifyTlsEnabled_neverPassesVerifyPeerOrVerifyHost(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->with(false)->willReturn('{}');

        $this->httpClient->expects(self::once())->method('request')
            ->with('GET', 'https://api.payplug.com/payments/pay_123', ['headers' => [], 'timeout' => 10])
            ->willReturn($response);

        $this->adapter->get('https://api.payplug.com/payments/pay_123');
    }
}
