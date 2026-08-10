<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Auth;

use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SyliusUnifiedApiHttpClient implements IUnifiedApiHttpClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string}
     */
    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, ['headers' => $headers]);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string}
     */
    public function postJson(string $url, array $body, array $headers = []): array
    {
        return $this->request('POST', $url, ['json' => $body, 'headers' => $headers]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{status: int, body: string}
     */
    private function request(string $method, string $url, array $options): array
    {
        try {
            $response = $this->httpClient->request($method, $url, $options);

            return [
                'status' => $response->getStatusCode(),
                // false = don't throw on non-2xx; AbstractUnifiedApiService itself checks the status.
                'body' => $response->getContent(false),
            ];
        } catch (TransportExceptionInterface $e) {
            return ['status' => 0, 'body' => $e->getMessage()];
        }
    }
}
