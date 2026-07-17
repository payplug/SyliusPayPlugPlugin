<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Auth;

use PayplugUnifiedCore\Contracts\IOAuthHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SyliusOAuthHttpClient implements IOAuthHttpClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @param array<string, string> $formParams
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string}
     */
    public function post(string $url, array $formParams, array $headers = []): array
    {
        $response = $this->httpClient->request('POST', $url, [
            'body' => http_build_query($formParams),
            'headers' => $headers,
        ]);

        return [
            'status' => $response->getStatusCode(),
            // false = don't throw on non-2xx; OAuth2Client itself checks the status.
            'body' => $response->getContent(false),
        ];
    }
}
