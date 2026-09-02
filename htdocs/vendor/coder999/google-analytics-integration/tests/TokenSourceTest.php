<?php

declare(strict_types=1);

namespace Mtmd\Ga4\Tests;

use Mtmd\Ga4\Cache\ArrayCache;
use Mtmd\Ga4\ServiceAccount;
use Mtmd\Ga4\Tests\Support\FakeHttp;
use Mtmd\Ga4\Tests\Support\TestKey;
use Mtmd\Ga4\TokenSource;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TokenSourceTest extends TestCase
{
    private function source(
        FakeHttp $http,
        ArrayCache $cache,
        int $now = 1_700_000_000,
        string $scope = TokenSource::SCOPE_READONLY,
    ): TokenSource {
        return new TokenSource(
            ServiceAccount::fromJson(TestKey::serviceAccountJson()),
            $cache,
            $http,
            $scope,
            'ga_token_cache',
            static fn (): int => $now,
        );
    }

    public function testExchangesASignedJwtForAnAccessToken(): void
    {
        $http = new FakeHttp();
        $http->queue(200, json_encode(['access_token' => 'tok-123', 'expires_in' => 3600], JSON_THROW_ON_ERROR));

        $this->assertSame('tok-123', $this->source($http, new ArrayCache())->accessToken());

        $request = $http->requests()[0];
        $this->assertSame(TokenSource::TOKEN_URL, $request['url']);
        $this->assertContains('Content-Type: application/x-www-form-urlencoded', $request['headers']);
    }

    public function testTheAssertionIsARealRs256JwtWithTheRequestedScope(): void
    {
        $http = new FakeHttp();
        $http->queue(200, json_encode(['access_token' => 'tok-123', 'expires_in' => 3600], JSON_THROW_ON_ERROR));

        $this->source($http, new ArrayCache(), 1_700_000_000, TokenSource::SCOPE_EDIT)->accessToken();

        parse_str($http->requests()[0]['body'], $form);
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);

        [$header64, $claims64, $signature64] = explode('.', (string) $form['assertion']);
        $decode = static fn (string $s): string => (string) base64_decode(strtr($s, '-_', '+/'), true);

        $header = json_decode($decode($header64), true);
        $claims = json_decode($decode($claims64), true);

        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $header);
        $this->assertSame(TokenSource::SCOPE_EDIT, $claims['scope']);
        $this->assertSame(TokenSource::TOKEN_URL, $claims['aud']);
        $this->assertSame('ga-reader@example.iam.gserviceaccount.com', $claims['iss']);
        $this->assertSame(1_700_000_000, $claims['iat']);
        $this->assertSame(1_700_003_600, $claims['exp']);

        $this->assertSame(1, openssl_verify(
            $header64 . '.' . $claims64,
            $decode($signature64),
            TestKey::publicKey(),
            OPENSSL_ALGO_SHA256
        ));
    }

    public function testReusesACachedTokenWithoutAnotherHttpCall(): void
    {
        $cache = new ArrayCache();
        $cache->set('ga_token_cache', ['token' => 'cached-tok', 'expires' => 1_700_000_500, 'scope' => TokenSource::SCOPE_READONLY]);

        $http = new FakeHttp(); // nothing queued: a second call would throw

        $this->assertSame('cached-tok', $this->source($http, $cache)->accessToken());
        $this->assertSame([], $http->requests());
    }

    public function testRefreshesATokenInsideTheSixtySecondSkew(): void
    {
        $cache = new ArrayCache();
        $cache->set('ga_token_cache', ['token' => 'stale-tok', 'expires' => 1_700_000_030, 'scope' => TokenSource::SCOPE_READONLY]);

        $http = new FakeHttp();
        $http->queue(200, json_encode(['access_token' => 'fresh-tok', 'expires_in' => 3600], JSON_THROW_ON_ERROR));

        $this->assertSame('fresh-tok', $this->source($http, $cache)->accessToken());
    }

    public function testCachesTheTokenWithAnAbsoluteExpiry(): void
    {
        $cache = new ArrayCache();
        $http  = new FakeHttp();
        $http->queue(200, json_encode(['access_token' => 'tok-123', 'expires_in' => 3600], JSON_THROW_ON_ERROR));

        $this->source($http, $cache)->accessToken();

        $this->assertSame(
            ['token' => 'tok-123', 'expires' => 1_700_003_600, 'scope' => TokenSource::SCOPE_READONLY],
            $cache->get('ga_token_cache')
        );
    }

    public function testRaisesTheGoogleErrorDescriptionOnFailure(): void
    {
        $http = new FakeHttp();
        $http->queue(400, json_encode(['error_description' => 'Invalid JWT Signature.'], JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid JWT Signature.');

        $this->source($http, new ArrayCache())->accessToken();
    }

    public function testDoesNotReuseCachedReadonlyTokenForEditScope(): void
    {
        $cache = new ArrayCache();
        // Seed with a SCOPE_READONLY token
        $cache->set('ga_token_cache', ['token' => 'readonly-tok', 'expires' => 1_700_001_000, 'scope' => TokenSource::SCOPE_READONLY]);

        $http = new FakeHttp();
        $http->queue(200, json_encode(['access_token' => 'edit-tok', 'expires_in' => 3600], JSON_THROW_ON_ERROR));

        // Request with SCOPE_EDIT should not reuse the readonly token
        $this->assertSame('edit-tok', $this->source($http, $cache, 1_700_000_000, TokenSource::SCOPE_EDIT)->accessToken());

        // Verify a request was made (readonly token was not reused)
        $this->assertNotEmpty($http->requests());
    }

    public function testDoesNotHandAnEditScopedTokenToAReadonlyCaller(): void
    {
        // The direction the scope-in-cache fix actually exists to prevent:
        // an analytics.edit bearer token (full write access to the GA
        // property) being handed to a caller that only asked for
        // analytics.readonly, because both callers share a cache key.
        $cache = new ArrayCache();
        $cache->set('ga_token_cache', ['token' => 'edit-tok', 'expires' => 1_700_001_000, 'scope' => TokenSource::SCOPE_EDIT]);

        $http = new FakeHttp();
        $http->queue(200, json_encode(['access_token' => 'readonly-tok', 'expires_in' => 3600], JSON_THROW_ON_ERROR));

        $this->assertSame('readonly-tok', $this->source($http, $cache, 1_700_000_000, TokenSource::SCOPE_READONLY)->accessToken());
        $this->assertNotEmpty($http->requests());
    }

    public function testTreatsACacheEntryWithNoScopeAtAllAsAMiss(): void
    {
        // The half of the fix that actually fires at retrofit: the four
        // production sites' existing token caches hold plain ['token',
        // 'expires'] rows with no 'scope' key, written before this package
        // existed. Those must refetch too, not just a mismatched scope.
        $cache = new ArrayCache();
        $cache->set('ga_token_cache', ['token' => 'legacy-tok', 'expires' => 1_700_001_000]);

        $http = new FakeHttp();
        $http->queue(200, json_encode(['access_token' => 'fresh-tok', 'expires_in' => 3600], JSON_THROW_ON_ERROR));

        $this->assertSame('fresh-tok', $this->source($http, $cache)->accessToken());
        $this->assertNotEmpty($http->requests());
    }
}
