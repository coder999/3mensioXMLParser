<?php

declare(strict_types=1);

namespace Coder999\Ga4;

use RuntimeException;

/**
 * GA4 Admin API writes. Requires TokenSource::SCOPE_EDIT and a service
 * account holding Editor on the GA account.
 *
 * Accounts cannot be created through this API -- provisionAccountTicket is
 * a browser redirect flow (checked against Google's v1beta reference,
 * 2026-09-01). The account must already exist.
 */
final class Admin
{
    public const BASE_URL = 'https://analyticsadmin.googleapis.com/v1beta';

    public function __construct(
        private readonly TokenSource $tokens,
        private readonly HttpInterface $http,
    ) {
    }

    /** @return list<array{name:string, displayName:string}> */
    public function listAccounts(): array
    {
        $response = $this->get('/accounts');

        $accounts = [];
        foreach ($response['accounts'] ?? [] as $account) {
            $accounts[] = [
                'name'        => (string) ($account['name'] ?? ''),
                'displayName' => (string) ($account['displayName'] ?? ''),
            ];
        }

        return $accounts;
    }

    /** @return array{name:string, propertyId:string} */
    public function createProperty(
        string $accountId,
        string $displayName,
        string $timeZone,
        string $currencyCode = 'USD',
    ): array {
        $parent = str_starts_with($accountId, 'accounts/') ? $accountId : 'accounts/' . $accountId;

        $response = $this->post('/properties', [
            'parent'       => $parent,
            'displayName'  => $displayName,
            'timeZone'     => $timeZone,
            'currencyCode' => $currencyCode,
        ]);

        $name = (string) ($response['name'] ?? '');

        return ['name' => $name, 'propertyId' => str_replace('properties/', '', $name)];
    }

    /** @return array{name:string, measurementId:string} */
    public function createWebDataStream(string $propertyId, string $displayName, string $defaultUri): array
    {
        $propertyId = str_replace('properties/', '', $propertyId);

        $stream = $this->post('/properties/' . rawurlencode($propertyId) . '/dataStreams', [
            'type'          => 'WEB_DATA_STREAM',
            'displayName'   => $displayName,
            'webStreamData' => ['defaultUri' => $defaultUri],
        ]);

        $name          = (string) ($stream['name'] ?? '');
        $measurementId = (string) ($stream['webStreamData']['measurementId'] ?? '');

        if ($measurementId === '' && $name !== '') {
            // Google's reference does not promise measurementId in the create
            // response, so read it back rather than assume.
            $stream        = $this->get('/' . $name);
            $measurementId = (string) ($stream['webStreamData']['measurementId'] ?? '');
        }

        if ($measurementId === '') {
            throw new RuntimeException(
                'Data stream was created (' . $name . ') but no measurement ID could be resolved for it.'
            );
        }

        return ['name' => $name, 'measurementId' => $measurementId];
    }

    /** @return array<string, mixed> */
    private function get(string $path): array
    {
        return $this->decode($this->http->get(self::BASE_URL . $path, $this->headers()));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        return $this->decode($this->http->post(
            self::BASE_URL . $path,
            json_encode($body, JSON_THROW_ON_ERROR),
            $this->headers()
        ));
    }

    /** @return array<int, string> */
    private function headers(): array
    {
        return [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->tokens->accessToken(),
        ];
    }

    /**
     * @param array{status:int, body:string} $response
     * @return array<string, mixed>
     */
    private function decode(array $response): array
    {
        $decoded = json_decode($response['body'], true);

        if ($response['status'] >= 400) {
            $message = is_array($decoded)
                ? ($decoded['error']['message'] ?? 'HTTP ' . $response['status'])
                : 'HTTP ' . $response['status'];

            throw new RuntimeException('GA4 Admin API error: ' . $message);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
