<?php

declare(strict_types=1);

namespace Coder999\Ga4\Tests;

use Coder999\Ga4\Cache\ArrayCache;
use Coder999\Ga4\Client;
use Coder999\Ga4\Credentials;
use Coder999\Ga4\Dashboard;
use Coder999\Ga4\ServiceAccount;
use Coder999\Ga4\Tests\Support\FakeHttp;
use Coder999\Ga4\Tests\Support\TestKey;
use Coder999\Ga4\TokenSource;
use PHPUnit\Framework\TestCase;

final class DashboardTest extends TestCase
{
    private function client(FakeHttp $http): Client
    {
        $cache = new ArrayCache();
        $cache->set('ga_token_cache', [
            'token'   => 'tok-abc',
            'expires' => time() + 3600,
            'scope'   => TokenSource::SCOPE_READONLY,
        ]);
        $account = ServiceAccount::fromJson(TestKey::serviceAccountJson());

        return new Client(
            new Credentials($account, '123456789'),
            new TokenSource($account, $cache, $http, TokenSource::SCOPE_READONLY),
            $http
        );
    }

    /** Queue the four canned report responses, in the order Dashboard requests them. */
    private function queueAllFourReports(FakeHttp $http): void
    {
        // 1) daily series
        $http->queue(200, json_encode(['rows' => [
            ['dimensionValues' => [['value' => '20260801']],
             'metricValues'    => [['value' => '10'], ['value' => '12'], ['value' => '30']]],
            ['dimensionValues' => [['value' => '20260802']],
             'metricValues'    => [['value' => '20'], ['value' => '22'], ['value' => '60']]],
        ]], JSON_THROW_ON_ERROR));

        // 2) totals, current then previous window
        $http->queue(200, json_encode(['rows' => [
            ['dimensionValues' => [['value' => 'date_range_0']],
             'metricValues'    => [['value' => '30'], ['value' => '34'], ['value' => '90']]],
            ['dimensionValues' => [['value' => 'date_range_1']],
             'metricValues'    => [['value' => '25'], ['value' => '28'], ['value' => '70']]],
        ]], JSON_THROW_ON_ERROR));

        // 3) top pages
        $http->queue(200, json_encode(['rows' => [
            ['dimensionValues' => [['value' => '/']],
             'metricValues'    => [['value' => '50'], ['value' => '20']]],
        ]], JSON_THROW_ON_ERROR));

        // 4) top locations, including a row that must be dropped
        $http->queue(200, json_encode(['rows' => [
            ['dimensionValues' => [['value' => 'Denver'], ['value' => 'United States']],
             'metricValues'    => [['value' => '18']]],
            ['dimensionValues' => [['value' => '(not set)'], ['value' => 'Germany']],
             'metricValues'    => [['value' => '3']]],
            ['dimensionValues' => [['value' => '(not set)'], ['value' => '(not set)']],
             'metricValues'    => [['value' => '9']]],
        ]], JSON_THROW_ON_ERROR));
    }

    public function testBuildsTheFullBundleFromFourReports(): void
    {
        $http = new FakeHttp();
        $this->queueAllFourReports($http);

        $data = (new Dashboard($this->client($http), new ArrayCache(), 3600, static fn (): int => 1_700_000_000))->data();

        $this->assertSame(
            [['date' => '20260801', 'users' => 10, 'sessions' => 12, 'pageviews' => 30],
             ['date' => '20260802', 'users' => 20, 'sessions' => 22, 'pageviews' => 60]],
            $data['daily']
        );
        $this->assertSame(['users' => 30, 'sessions' => 34, 'pageviews' => 90], $data['totals']);
        $this->assertSame(['users' => 25, 'sessions' => 28, 'pageviews' => 70], $data['prev']);
        $this->assertSame([['path' => '/', 'views' => 50, 'users' => 20]], $data['top_pages']);
        $this->assertSame(1_700_000_000, $data['fetched_at']);
        $this->assertCount(4, $http->requests());
    }

    public function testDropsALocationRowOnlyWhenCityAndCountryAreBothUnresolved(): void
    {
        $http = new FakeHttp();
        $this->queueAllFourReports($http);

        $data = (new Dashboard($this->client($http), new ArrayCache()))->data();

        $this->assertSame(
            [['city' => 'Denver', 'country' => 'United States', 'users' => 18],
             ['city' => '(not set)', 'country' => 'Germany', 'users' => 3]],
            $data['top_locations']
        );
    }

    /** A full, current-shape bundle -- as Dashboard itself would write. */
    private function fullBundle(int $fetchedAt): array
    {
        return [
            'daily'         => [],
            'totals'        => [],
            'prev'          => [],
            'top_pages'     => [],
            'top_locations' => [],
            'fetched_at'    => $fetchedAt,
        ];
    }

    public function testServesAFreshCacheWithoutAnyHttpCalls(): void
    {
        $cache = new ArrayCache();
        $cache->set('ga_report_cache', $this->fullBundle(1_699_999_000));

        $http = new FakeHttp(); // nothing queued: any request would throw

        $data = (new Dashboard($this->client($http), $cache, 3600, static fn (): int => 1_700_000_000))->data();

        $this->assertSame(1_699_999_000, $data['fetched_at']);
        $this->assertSame([], $http->requests());
    }

    public function testRefetchesWhenTheCacheIsOlderThanTheTtl(): void
    {
        $cache = new ArrayCache();
        $cache->set('ga_report_cache', $this->fullBundle(1_699_990_000));

        $http = new FakeHttp();
        $this->queueAllFourReports($http);

        $data = (new Dashboard($this->client($http), $cache, 3600, static fn (): int => 1_700_000_000))->data();

        $this->assertSame(1_700_000_000, $data['fetched_at']);
        $this->assertCount(4, $http->requests());
    }

    public function testTreatsALegacyShapedCacheEntryAsAMiss(): void
    {
        // Byte-identical cache key to the four legacy ga.php clients this
        // package replaces. Three of the four write this exact shape --
        // no top_locations -- with a fresh fetched_at. It must be treated
        // as a miss, not served, or the new admin page's iteration over
        // top_locations hits undefined-key warnings for up to an hour on
        // the very first retrofit of a site.
        $cache = new ArrayCache();
        $cache->set('ga_report_cache', [
            'daily'      => [],
            'totals'     => [],
            'prev'       => [],
            'top_pages'  => [],
            'fetched_at' => 1_699_999_900, // 100s old, well inside the TTL
        ]);

        $http = new FakeHttp();
        $this->queueAllFourReports($http);

        $data = (new Dashboard($this->client($http), $cache, 3600, static fn (): int => 1_700_000_000))->data();

        $this->assertSame(1_700_000_000, $data['fetched_at']);
        $this->assertCount(4, $http->requests());
        $this->assertArrayHasKey('top_locations', $data);
    }

    public function testForceBypassesAFreshCache(): void
    {
        $cache = new ArrayCache();
        $cache->set('ga_report_cache', ['daily' => [], 'fetched_at' => 1_699_999_000]);

        $http = new FakeHttp();
        $this->queueAllFourReports($http);

        $data = (new Dashboard($this->client($http), $cache, 3600, static fn (): int => 1_700_000_000))->data(true);

        $this->assertSame(1_700_000_000, $data['fetched_at']);
        $this->assertCount(4, $http->requests());
    }

    public function testStoresTheBundleInTheCache(): void
    {
        $cache = new ArrayCache();
        $http  = new FakeHttp();
        $this->queueAllFourReports($http);

        (new Dashboard($this->client($http), $cache, 3600, static fn (): int => 1_700_000_000))->data();

        $this->assertSame(1_700_000_000, $cache->get('ga_report_cache')['fetched_at']);
    }
}
