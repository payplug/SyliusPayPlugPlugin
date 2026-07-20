<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Auth;

use PayPlug\SyliusPayPlugPlugin\Auth\SyliusOAuthHttpClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class SyliusOAuthHttpClientTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;

    private SyliusOAuthHttpClient $adapter;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->adapter = new SyliusOAuthHttpClient($this->httpClient);
    }

    // -------------------------------------------------------------------------
    // post() — delegates to HttpClientInterface with form-encoded body
    // -------------------------------------------------------------------------

    /**
     * Verifies the form params are sent as a URL-encoded body (not a raw array), and the given
     * headers are passed through unchanged.
     */
    public function testPost_sendsFormEncodedBodyAndHeaders(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->with(false)->willReturn('{"access_token":"jwt"}');

        $this->httpClient->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                'https://api-qa.payplug.com/oauth2/token',
                [
                    'body' => 'grant_type=authorization_code&client_id=client_abc',
                    'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                ],
            )
            ->willReturn($response)
        ;

        $result = $this->adapter->post(
            'https://api-qa.payplug.com/oauth2/token',
            ['grant_type' => 'authorization_code', 'client_id' => 'client_abc'],
            ['Content-Type' => 'application/x-www-form-urlencoded'],
        );

        self::assertSame(['status' => 200, 'body' => '{"access_token":"jwt"}'], $result);
    }

    // -------------------------------------------------------------------------
    // post() — non-2xx status does not throw (caller decides how to react)
    // -------------------------------------------------------------------------

    /**
     * getContent(false) is used specifically so a 4xx/5xx response body is still returned
     * instead of throwing — OAuth2Client itself is responsible for checking the status.
     */
    public function testPost_onNon2xxStatus_returnsStatusAndBodyWithoutThrowing(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(401);
        $response->method('getContent')->with(false)->willReturn('{"error":"invalid_client"}');

        $this->httpClient->method('request')->willReturn($response);

        $result = $this->adapter->post('https://api-qa.payplug.com/oauth2/token', ['grant_type' => 'client_credentials']);

        self::assertSame(['status' => 401, 'body' => '{"error":"invalid_client"}'], $result);
    }

    // -------------------------------------------------------------------------
    // post() — default empty headers array is accepted
    // -------------------------------------------------------------------------

    public function testPost_withNoHeadersArgument_defaultsToEmptyHeaders(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn('{}');

        $this->httpClient->expects(self::once())
            ->method('request')
            ->with(self::anything(), self::anything(), self::callback(
                static fn (array $options): bool => [] === $options['headers'],
            ))
            ->willReturn($response)
        ;

        $this->adapter->post('https://api-qa.payplug.com/oauth2/token', ['grant_type' => 'client_credentials']);
    }
}
