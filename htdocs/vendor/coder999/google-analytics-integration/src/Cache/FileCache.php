<?php

declare(strict_types=1);

namespace Mtmd\Ga4\Cache;

use InvalidArgumentException;
use Mtmd\Ga4\CacheInterface;
use RuntimeException;

/**
 * JSON-file cache for sites with no database. Keep the directory OUTSIDE
 * the web root -- it holds an OAuth access token.
 *
 * The directory must also not be world-writable. If it is, another local
 * user can pre-create the cache file (or a symlink in its place) before
 * this class ever runs; `set()`'s write and permission tightening would
 * then follow that symlink, and `ensureDirectory()` treats an
 * already-existing directory as fine regardless of who created it. Either
 * one hands another local user the cached token, or lets them redirect
 * writes/chmods to a file of their choosing.
 */
final class FileCache implements CacheInterface
{
    public function __construct(private readonly string $directory)
    {
    }

    public function get(string $key): ?array
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function set(string $key, array $value): void
    {
        $file = $this->path($key);
        $this->ensureDirectory();

        // Create the file at 0600 from the moment it exists, rather than
        // writing world-readable and tightening the mode afterwards --
        // that window is real, and a chmod that silently fails leaves the
        // file world-readable forever. umask affects file creation only,
        // so this cannot widen an existing file's permissions.
        $previousUmask = umask(0177);
        try {
            $written = file_put_contents($file, json_encode($value, JSON_THROW_ON_ERROR), LOCK_EX);
        } finally {
            umask($previousUmask);
        }

        if ($written === false) {
            throw new RuntimeException('Could not write cache file: ' . $file);
        }
    }

    private function path(string $key): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
            throw new InvalidArgumentException(
                'Cache key must contain only letters, digits, underscore and hyphen; got: ' . $key
            );
        }

        return $this->directory . '/' . $key . '.json';
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }
        if (!mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Could not create cache directory: ' . $this->directory);
        }
    }
}
