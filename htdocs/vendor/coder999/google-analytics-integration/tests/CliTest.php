<?php

declare(strict_types=1);

namespace Coder999\Ga4\Tests;

use Coder999\Ga4\Cache\ArrayCache;
use Coder999\Ga4\Cli;
use Coder999\Ga4\Tests\Support\FakeHttp;
use Coder999\Ga4\Tests\Support\TestKey;
use Coder999\Ga4\TokenSource;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    /** @return array<string,string> */
    private function env(): array
    {
        return ['GA_SERVICE_ACCOUNT_JSON' => TestKey::serviceAccountJson()];
    }

    private function cache(): ArrayCache
    {
        $cache = new ArrayCache();
        $cache->set('ga_admin_token_cache', [
            'token'   => 'tok-edit',
            'expires' => time() + 3600,
            'scope'   => TokenSource::SCOPE_EDIT,
        ]);

        return $cache;
    }

    public function testListAccountsPrintsIdAndName(): void
    {
        $http = new FakeHttp();
        $http->queue(200, json_encode(['accounts' => [
            ['name' => 'accounts/100', 'displayName' => 'Tuttle'],
        ]], JSON_THROW_ON_ERROR));

        $result = (new Cli($http, $this->cache()))->run(['list-accounts'], $this->env());

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('accounts/100', $result['out']);
        $this->assertStringContainsString('Tuttle', $result['out']);
    }

    public function testCreatePrintsTheTwoEnvLines(): void
    {
        $http = new FakeHttp();
        $http->queue(201, json_encode(['name' => 'properties/456789'], JSON_THROW_ON_ERROR));
        $http->queue(201, json_encode([
            'name'          => 'properties/456789/dataStreams/999',
            'webStreamData' => ['measurementId' => 'G-ABC1234567'],
        ], JSON_THROW_ON_ERROR));

        $result = (new Cli($http, $this->cache()))->run([
            'create',
            '--account', '100',
            '--name', 'DiasLab',
            '--domain', 'https://diaslab.org',
            '--timezone', 'America/Denver',
        ], $this->env());

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('GA4_PROPERTY_ID=456789', $result['out']);
        $this->assertStringContainsString('GA4_MEASUREMENT_ID=G-ABC1234567', $result['out']);
    }

    public function testCreatePrintsTheTimezoneAndCurrencyInTheSuccessOutput(): void
    {
        $http = new FakeHttp();
        $http->queue(201, json_encode(['name' => 'properties/456789'], JSON_THROW_ON_ERROR));
        $http->queue(201, json_encode([
            'name'          => 'properties/456789/dataStreams/999',
            'webStreamData' => ['measurementId' => 'G-ABC1234567'],
        ], JSON_THROW_ON_ERROR));

        $result = (new Cli($http, $this->cache()))->run([
            'create',
            '--account', '100',
            '--name', 'DiasLab',
            '--domain', 'https://diaslab.org',
            '--timezone', 'America/Denver',
        ], $this->env());

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('timezone America/Denver', $result['out']);
        $this->assertStringContainsString('currency USD', $result['out']);
    }

    public function testCreateFailsWhenARequiredOptionIsMissing(): void
    {
        $result = (new Cli(new FakeHttp(), $this->cache()))->run(
            ['create', '--account', '100', '--name', 'DiasLab'],
            $this->env()
        );

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('--domain', $result['out']);
    }

    public function testCreateFailsWhenTimezoneIsMissing(): void
    {
        $result = (new Cli(new FakeHttp(), $this->cache()))->run(
            ['create', '--account', '100', '--name', 'DiasLab', '--domain', 'https://diaslab.org'],
            $this->env()
        );

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('--timezone', $result['out']);
    }

    public function testFailsClearlyWithoutTheServiceAccountKey(): void
    {
        $result = (new Cli(new FakeHttp(), $this->cache()))->run(['list-accounts'], []);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('GA_SERVICE_ACCOUNT_JSON', $result['out']);
    }

    public function testUnknownCommandPrintsUsage(): void
    {
        $result = (new Cli(new FakeHttp(), $this->cache()))->run(['frobnicate'], $this->env());

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('usage:', $result['out']);
    }

    public function testSurfacesAnApiErrorAsAFailureExitCode(): void
    {
        $http = new FakeHttp();
        $http->queue(403, json_encode(
            ['error' => ['message' => 'User does not have sufficient permissions for this account.']],
            JSON_THROW_ON_ERROR
        ));

        $result = (new Cli($http, $this->cache()))->run(['list-accounts'], $this->env());

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('sufficient permissions', $result['out']);
    }
}
