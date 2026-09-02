<?php

declare(strict_types=1);

namespace Mtmd\Ga4;

interface CacheInterface
{
    /** @return array<mixed>|null Null when absent or unreadable. */
    public function get(string $key): ?array;

    /** @param array<mixed> $value */
    public function set(string $key, array $value): void;
}
