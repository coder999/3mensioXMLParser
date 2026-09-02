<?php

declare(strict_types=1);

namespace Mtmd\Ga4;

interface HttpInterface
{
    /**
     * @param array<int, string> $headers Raw header lines, e.g. 'Content-Type: application/json'.
     * @return array{status:int, body:string}
     */
    public function get(string $url, array $headers): array;

    /**
     * @param array<int, string> $headers Raw header lines, e.g. 'Content-Type: application/json'.
     * @return array{status:int, body:string}
     */
    public function post(string $url, string $body, array $headers): array;
}
