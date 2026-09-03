<?php

declare(strict_types=1);

namespace Coder999\Ga4;

use RuntimeException;

/**
 * Exchanges a service-account JWT (RS256) for an OAuth2 access token and
 * caches it until just before expiry.
 */
final class TokenSource
{
    public const SCOPE_READONLY = 'https://www.googleapis.com/auth/analytics.readonly';
    public const SCOPE_EDIT     = 'https://www.googleapis.com/auth/analytics.edit';
    public const TOKEN_URL      = 'https://oauth2.googleapis.com/token';

    /** Refresh this many seconds before the token actually expires. */
    private const SKEW_SECONDS = 60;

    /** @var callable(): int */
    private $clock;

    public function __construct(
        private readonly ServiceAccount $account,
        private readonly CacheInterface $cache,
        private readonly HttpInterface $http,
        private readonly string $scope = self::SCOPE_READONLY,
        private readonly string $cacheKey = 'ga_token_cache',
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function accessToken(): string
    {
        $now = ($this->clock)();

        $cached = $this->cache->get($this->cacheKey);
        if (is_array($cached)
            && !empty($cached['token'])
            && (int) ($cached['expires'] ?? 0) > $now + self::SKEW_SECONDS
            && ($cached['scope'] ?? null) === $this->scope
        ) {
            return (string) $cached['token'];
        }

        $response = $this->http->post(
            self::TOKEN_URL,
            http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $this->assertion($now),
            ]),
            ['Content-Type: application/x-www-form-urlencoded']
        );

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded) || empty($decoded['access_token'])) {
            $message = is_array($decoded)
                ? ($decoded['error_description'] ?? $decoded['error'] ?? 'no access_token in response')
                : 'response was not JSON';

            throw new RuntimeException('Google token exchange failed: ' . $message);
        }

        $token   = (string) $decoded['access_token'];
        $expires = $now + (int) ($decoded['expires_in'] ?? 3600);
        $this->cache->set($this->cacheKey, ['token' => $token, 'expires' => $expires, 'scope' => $this->scope]);

        return $token;
    }

    private function assertion(int $now): string
    {
        $header = self::base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = self::base64url(json_encode([
            'iss'   => $this->account->clientEmail,
            'scope' => $this->scope,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ], JSON_THROW_ON_ERROR));

        $signature = '';
        $signed = openssl_sign(
            $header . '.' . $claims,
            $signature,
            $this->account->privateKey,
            OPENSSL_ALGO_SHA256
        );
        if ($signed === false) {
            throw new RuntimeException(
                'Could not sign the JWT -- check private_key in the service account JSON.'
            );
        }

        return $header . '.' . $claims . '.' . self::base64url($signature);
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
