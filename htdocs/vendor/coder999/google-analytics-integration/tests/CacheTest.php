<?php

declare(strict_types=1);

namespace Mtmd\Ga4\Tests;

use Mtmd\Ga4\Cache\ArrayCache;
use Mtmd\Ga4\Cache\FileCache;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ga4-cache-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->dir);
        }
    }

    public function testArrayCacheReturnsNullForAMissingKey(): void
    {
        $this->assertNull((new ArrayCache())->get('absent'));
    }

    public function testArrayCacheRoundTripsAValue(): void
    {
        $cache = new ArrayCache();
        $cache->set('token', ['token' => 'abc', 'expires' => 123]);
        $this->assertSame(['token' => 'abc', 'expires' => 123], $cache->get('token'));
    }

    public function testFileCacheReturnsNullForAMissingKey(): void
    {
        $this->assertNull((new FileCache($this->dir))->get('absent'));
    }

    public function testFileCacheRoundTripsAValue(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('report', ['fetched_at' => 42]);
        $this->assertSame(['fetched_at' => 42], $cache->get('report'));
    }

    public function testFileCachePersistsAcrossInstances(): void
    {
        (new FileCache($this->dir))->set('report', ['fetched_at' => 42]);
        $this->assertSame(['fetched_at' => 42], (new FileCache($this->dir))->get('report'));
    }

    public function testFileCacheCreatesThePrivateDirectoryAndWritesPrivateFiles(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('secretish', ['a' => 1]);

        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->dir)), -4));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->dir . '/secretish.json')), -4));
    }

    public function testFileCacheRejectsAKeyThatWouldEscapeTheDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FileCache($this->dir))->set('../escaped', ['a' => 1]);
    }

    public function testFileCacheTreatsCorruptContentAsAMiss(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('report', ['a' => 1]);
        file_put_contents($this->dir . '/report.json', '{not json');

        $this->assertNull($cache->get('report'));
    }
}
