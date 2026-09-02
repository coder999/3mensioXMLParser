<?php

declare(strict_types=1);

namespace Mtmd\Ga4\Cache;

use Mtmd\Ga4\CacheInterface;

final class ArrayCache implements CacheInterface
{
    /** @var array<string, array<mixed>> */
    private array $entries = [];

    public function get(string $key): ?array
    {
        return $this->entries[$key] ?? null;
    }

    public function set(string $key, array $value): void
    {
        $this->entries[$key] = $value;
    }
}
