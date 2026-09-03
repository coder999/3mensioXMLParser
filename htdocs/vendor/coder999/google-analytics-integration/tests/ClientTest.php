<?php

declare(strict_types=1);

namespace Coder999\Ga4\Tests;

use Coder999\Ga4\Cache\ArrayCache;
use Coder999\Ga4\Client;
use Coder999\Ga4\Credentials;
use Coder999\Ga4\ServiceAccount;
use Coder999\Ga4\Tests\Support\FakeHttp;
use Coder999\Ga4\Tests\Support\TestKey;
use Coder999\Ga4\TokenSource;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ClientTest extends TestCase
{
    private function client(FakeHttp $http, string $propertyId = '123456789'): Client
    {
        $cache = new ArrayCache();
        // Pre-seed a live token so these tests exercise runReport only.
        $cache->set('ga_token_cache', [
            'token'   => 'tok-abc',
            'expires' => time() + 3600,
            'scope'   => TokenSource::SCOPE_READONLY,
        ]);

        $account = ServiceAccount::fromJson(TestKey::serviceAccountJson());
        $tokens  = new TokenSource($account, $cache, $http, TokenSource::SCOPE_READONLY);

        return new Client(new Credentials($account, $propertyId), $tokens, $http);
    }

    public function testPostsToTheRunReportEndpointForTheConfiguredProperty(): void
    {
        $http = new FakeHttp();
        $http->queue(200, json_encode(['rows' => []], JSON_THROW_ON_ERROR));

        $this->client($http)->runReport(['metrics' => [['name' => 'activeUsers']]]);

        $request = $http->requests()[0];
        $this->assertSame(
            'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport',
            $request['url']
        );
        $this->assertContains('Authorization: Bearer tok-abc', $request['headers']);
        $this->assertContains('Content-Type: application/json', $request['headers']);
        $this->assertSame(['metrics' => [['name' => 'activeUsers']]], json_decode($request['body'], true));
    }

    public function testReturnsTheDecodedResponse(): void
    {
        $http = new FakeHttp();
        $http->queue(200, json_encode(['rows' => [['metricValues' => [['value' => '7']]]]], JSON_THROW_ON_ERROR));

        $result = $this->client($http)->runReport([]);

        $this->assertSame('7', $result['rows'][0]['metricValues'][0]['value']);
    }

    public function testRaisesTheGoogleErrorMessageOnFailure(): void
    {
        $http = new FakeHttp();
        $http->queue(403, json_encode(
            ['error' => ['message' => 'User does not have sufficient permissions for this property.']],
            JSON_THROW_ON_ERROR
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sufficient permissions');

        $this->client($http)->runReport([]);
    }

    public function testFallsBackToTheStatusCodeWhenTheErrorBodyIsNotJson(): void
    {
        $http = new FakeHttp();
        $http->queue(500, '<html>gateway</html>');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');

        $this->client($http)->runReport([]);
    }
}
