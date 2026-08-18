<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SyliusUnifiedApiHttpClient implements IUnifiedApiHttpClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    public function get(string $url, array $headers = []): array
    {
        return $this->send('GET', $url, ['headers' => $headers]);
    }

    public function postJson(string $url, array $body, array $headers = []): array
    {
        return $this->send('POST', $url, ['json' => $body, 'headers' => $headers]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{status: int, body: string}
     */
    private function send(string $method, string $url, array $options): array
    {
        $this->logger->debug('[PayPlug debug] Unified API raw request.', [
            'method' => $method,
            'url' => $url,
            'headers' => $options['headers'] ?? [],
            'json' => $options['json'] ?? null,
        ]);

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);

            $this->logger->debug('[PayPlug debug] Unified API raw response.', [
                'status' => $status,
                'body' => $body,
            ]);

            return [
                'status' => $status,
                'body' => $body,
            ];
        } catch (TransportExceptionInterface $e) {
            $this->logger->debug('[PayPlug debug] Unified API transport exception.', [
                'message' => $e->getMessage(),
            ]);

            return ['status' => 0, 'body' => $e->getMessage()];
        }
    }
}
