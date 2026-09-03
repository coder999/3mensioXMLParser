<?php

declare(strict_types=1);

namespace Coder999\Ga4;

/**
 * The report bundle the admin pages consume: a 28-day daily series, period
 * totals with previous-period comparison, top pages, and top locations.
 */
final class Dashboard
{
    public const CACHE_KEY = 'ga_report_cache';

    /** @var callable(): int */
    private $clock;

    public function __construct(
        private readonly Client $client,
        private readonly CacheInterface $cache,
        private readonly int $ttlSeconds = 3600,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @return array<string, mixed> */
    public function data(bool $force = false): array
    {
        $now = ($this->clock)();

        if (!$force) {
            $cached = $this->cache->get(self::CACHE_KEY);
            // The cache key is byte-identical to the one the legacy ga.php
            // clients this package replaces already write, and their
            // bundles are missing top_locations. Treat that shape as a
            // miss rather than serve it, the same discipline TokenSource
            // applies to a mismatched scope.
            if (isset($cached['top_locations'], $cached['daily'], $cached['totals'], $cached['prev'], $cached['top_pages'])
                && (int) ($cached['fetched_at'] ?? 0) > $now - $this->ttlSeconds) {
                return $cached;
            }
        }

        // Assigned to locals first so the four HTTP calls happen in this
        // exact order, which the tests queue responses against.
        $daily           = $this->daily();
        [$totals, $prev] = $this->totals();
        $topPages        = $this->topPages();
        $topLocations    = $this->topLocations();

        $data = [
            'daily'         => $daily,
            'totals'        => $totals,
            'prev'          => $prev,
            'top_pages'     => $topPages,
            'top_locations' => $topLocations,
            'fetched_at'    => $now,
        ];

        $this->cache->set(self::CACHE_KEY, $data);

        return $data;
    }

    /** @return list<array{date:string, users:int, sessions:int, pageviews:int}> */
    private function daily(): array
    {
        $response = $this->client->runReport([
            'dateRanges' => [['startDate' => '27daysAgo', 'endDate' => 'today']],
            'dimensions' => [['name' => 'date']],
            'metrics'    => [['name' => 'activeUsers'], ['name' => 'sessions'], ['name' => 'screenPageViews']],
            'orderBys'   => [['dimension' => ['dimensionName' => 'date']]],
        ]);

        $series = [];
        foreach ($response['rows'] ?? [] as $row) {
            $series[] = [
                'date'      => (string) $row['dimensionValues'][0]['value'],
                'users'     => (int) $row['metricValues'][0]['value'],
                'sessions'  => (int) $row['metricValues'][1]['value'],
                'pageviews' => (int) $row['metricValues'][2]['value'],
            ];
        }

        return $series;
    }

    /** @return array{0: array<string,int>, 1: array<string,int>} Current window, then previous. */
    private function totals(): array
    {
        $response = $this->client->runReport([
            'dateRanges' => [
                ['startDate' => '27daysAgo', 'endDate' => 'today'],
                ['startDate' => '55daysAgo', 'endDate' => '28daysAgo'],
            ],
            'metrics' => [['name' => 'activeUsers'], ['name' => 'sessions'], ['name' => 'screenPageViews']],
        ]);

        $empty = ['users' => 0, 'sessions' => 0, 'pageviews' => 0];
        $current = $empty;
        $previous = $empty;

        foreach ($response['rows'] ?? [] as $row) {
            $values = [
                'users'     => (int) $row['metricValues'][0]['value'],
                'sessions'  => (int) $row['metricValues'][1]['value'],
                'pageviews' => (int) $row['metricValues'][2]['value'],
            ];

            if (($row['dimensionValues'][0]['value'] ?? 'date_range_0') === 'date_range_0') {
                $current = $values;
            } else {
                $previous = $values;
            }
        }

        return [$current, $previous];
    }

    /** @return list<array{path:string, views:int, users:int}> */
    private function topPages(): array
    {
        $response = $this->client->runReport([
            'dateRanges' => [['startDate' => '27daysAgo', 'endDate' => 'today']],
            'dimensions' => [['name' => 'pagePath']],
            'metrics'    => [['name' => 'screenPageViews'], ['name' => 'activeUsers']],
            'orderBys'   => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
            'limit'      => 10,
        ]);

        $pages = [];
        foreach ($response['rows'] ?? [] as $row) {
            $pages[] = [
                'path'  => (string) $row['dimensionValues'][0]['value'],
                'views' => (int) $row['metricValues'][0]['value'],
                'users' => (int) $row['metricValues'][1]['value'],
            ];
        }

        return $pages;
    }

    /**
     * GA4 reports "(not set)" for traffic it cannot geo-resolve (bots, some
     * VPNs). A row is dropped only when city AND country are both unresolved,
     * since a known country with an unresolved city is still useful signal.
     *
     * @return list<array{city:string, country:string, users:int}>
     */
    private function topLocations(): array
    {
        $response = $this->client->runReport([
            'dateRanges' => [['startDate' => '27daysAgo', 'endDate' => 'today']],
            'dimensions' => [['name' => 'city'], ['name' => 'country']],
            'metrics'    => [['name' => 'activeUsers']],
            'orderBys'   => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]],
            'limit'      => 15,
        ]);

        $locations = [];
        foreach ($response['rows'] ?? [] as $row) {
            $city    = (string) $row['dimensionValues'][0]['value'];
            $country = (string) $row['dimensionValues'][1]['value'];

            if ($city === '(not set)' && $country === '(not set)') {
                continue;
            }

            $locations[] = ['city' => $city, 'country' => $country, 'users' => (int) $row['metricValues'][0]['value']];
        }

        return $locations;
    }
}
