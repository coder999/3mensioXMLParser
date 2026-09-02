<?php

declare(strict_types=1);

namespace Mtmd\Ga4\Tests;

use Mtmd\Ga4\Admin;
use Mtmd\Ga4\Cache\ArrayCache;
use Mtmd\Ga4\ServiceAccount;
use Mtmd\Ga4\Tests\Support\FakeHttp;
use Mtmd\Ga4\Tests\Support\TestKey;
use Mtmd\Ga4\TokenSource;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AdminTest extends TestCase
{
    private function admin(FakeHttp $http): Admin
    {
        $cache = new ArrayCache();
        $cache->set('ga_admin_token_cache', [
            'token'   => 'tok-edit',
            'expires' => time() + 3600,
            'scope'   => TokenSource::SCOPE_EDIT,
        ]);
        $account = ServiceAccount::fromJson(TestKey::serviceAccountJson());

        return new Admin(
            new TokenSource($account, $cache, $http, TokenSource::SCOPE_EDIT, 'ga_admin_token_cache'),
            $http
        );
    }

    public function testListsAccounts(): void
    {
        $http = new FakeHttp();
        $http->queue(200, json_encode(['accounts' => [
            ['name' => 'accounts/100', 'displayName' => 'Tuttle'],
        ]], JSON_THROW_ON_ERROR));

        $accounts = $this->admin($http)->listAccounts();

        $this->assertSame([['name' => 'accounts/100', 'displayName' => 'Tuttle']], $accounts);
        $this->assertSame('GET', $http->requests()[0]['method']);
        $this->assertSame('https://analyticsadmin.googleapis.com/v1beta/accounts', $http->requests()[0]['url']);
        $this->assertContains('Authorization: Bearer tok-edit', $http->requests()[0]['headers']);
    }

    public function testReturnsAnEmptyListWhenNoAccountsAreVisible(): void
    {
        $http = new FakeHttp();
        $http->queue(200, '{}');

        $this->assertSame([], $this->admin($http)->listAccounts());
    }

    public function testCreatesAPropertyUnderAnAccount(): void
    {
        $http = new FakeHttp();
        $http->queue(201, json_encode(['name' => 'properties/456789'], JSON_THROW_ON_ERROR));

        $result = $this->admin($http)->createProperty('100', 'DiasLab', 'America/Denver');

        $this->assertSame(['name' => 'properties/456789', 'propertyId' => '456789'], $result);

        $request = $http->requests()[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://analyticsadmin.googleapis.com/v1beta/properties', $request['url']);
        $this->assertSame([
            'parent'       => 'accounts/100',
            'displayName'  => 'DiasLab',
            'timeZone'     => 'America/Denver',
            'currencyCode' => 'USD',
        ], json_decode($request['body'], true));
    }

    public function testAcceptsAnAccountIdThatAlreadyCarriesThePrefix(): void
    {
        $http = new FakeHttp();
        $http->queue(201, json_encode(['name' => 'properties/456789'], JSON_THROW_ON_ERROR));

        $this->admin($http)->createProperty('accounts/100', 'DiasLab', 'America/Denver');

        $this->assertSame('accounts/100', json_decode($http->requests()[0]['body'], true)['parent']);
    }

    public function testCreatesAWebDataStreamAndReadsTheMeasurementIdFromTheResponse(): void
    {
        $http = new FakeHttp();
        $http->queue(201, json_encode([
            'name'          => 'properties/456789/dataStreams/999',
            'webStreamData' => ['measurementId' => 'G-ABC1234567'],
        ], JSON_THROW_ON_ERROR));

        $result = $this->admin($http)->createWebDataStream('456789', 'DiasLab web', 'https://diaslab.org');

        $this->assertSame([
            'name'          => 'properties/456789/dataStreams/999',
            'measurementId' => 'G-ABC1234567',
        ], $result);

        $request = $http->requests()[0];
        $this->assertSame(
            'https://analyticsadmin.googleapis.com/v1beta/properties/456789/dataStreams',
            $request['url']
        );
        $this->assertSame([
            'type'          => 'WEB_DATA_STREAM',
            'displayName'   => 'DiasLab web',
            'webStreamData' => ['defaultUri' => 'https://diaslab.org'],
        ], json_decode($request['body'], true));
    }

    public function testFallsBackToReadingTheStreamWhenCreateOmitsTheMeasurementId(): void
    {
        $http = new FakeHttp();
        $http->queue(201, json_encode(['name' => 'properties/456789/dataStreams/999'], JSON_THROW_ON_ERROR));
        $http->queue(200, json_encode([
            'name'          => 'properties/456789/dataStreams/999',
            'webStreamData' => ['measurementId' => 'G-ABC1234567'],
        ], JSON_THROW_ON_ERROR));

        $result = $this->admin($http)->createWebDataStream('456789', 'DiasLab web', 'https://diaslab.org');

        $this->assertSame('G-ABC1234567', $result['measurementId']);
        $this->assertSame('GET', $http->requests()[1]['method']);
        $this->assertSame(
            'https://analyticsadmin.googleapis.com/v1beta/properties/456789/dataStreams/999',
            $http->requests()[1]['url']
        );
    }

    public function testRaisesWhenTheMeasurementIdCannotBeResolved(): void
    {
        $http = new FakeHttp();
        $http->queue(201, json_encode(['name' => 'properties/456789/dataStreams/999'], JSON_THROW_ON_ERROR));
        $http->queue(200, json_encode(['name' => 'properties/456789/dataStreams/999'], JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('measurement ID');

        $this->admin($http)->createWebDataStream('456789', 'DiasLab web', 'https://diaslab.org');
    }

    public function testSurfacesTheGoogleErrorMessage(): void
    {
        $http = new FakeHttp();
        $http->queue(403, json_encode(
            ['error' => ['message' => 'User does not have sufficient permissions for this account.']],
            JSON_THROW_ON_ERROR
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sufficient permissions');

        $this->admin($http)->createProperty('100', 'DiasLab', 'America/Denver');
    }
}
