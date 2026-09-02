<?php

declare(strict_types=1);

namespace Mtmd\Ga4;

use RuntimeException;

final class Client
{
    public const BASE_URL = 'https://analyticsdata.googleapis.com/v1beta';

    public function __construct(
        private readonly Credentials $credentials,
        private readonly TokenSource $tokens,
        private readonly HttpInterface $http,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function runReport(array $body): array
    {
        $url = self::BASE_URL . '/properties/' . rawurlencode($this->credentials->propertyId) . ':runReport';

        $response = $this->http->post($url, json_encode($body, JSON_THROW_ON_ERROR), [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->tokens->accessToken(),
        ]);

        $decoded = json_decode($response['body'], true);

        if ($response['status'] >= 400) {
            $message = is_array($decoded)
                ? ($decoded['error']['message'] ?? 'HTTP ' . $response['status'])
                : 'HTTP ' . $response['status'];

            throw new RuntimeException('GA4 Data API error: ' . $message);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
