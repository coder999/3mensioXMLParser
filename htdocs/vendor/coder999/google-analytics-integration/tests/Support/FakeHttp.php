<?php

declare(strict_types=1);

namespace Coder999\Ga4\Tests\Support;

use Coder999\Ga4\HttpInterface;
use RuntimeException;

final class FakeHttp implements HttpInterface
{
    /** @var list<array{status:int, body:string}> */
    private array $responses = [];

    /** @var list<array{method:string, url:string, body:string, headers:array<int,string>}> */
    private array $requests = [];

    public function queue(int $status, string $body): void
    {
        $this->responses[] = ['status' => $status, 'body' => $body];
    }

    public function get(string $url, array $headers): array
    {
        return $this->record('GET', $url, '', $headers);
    }

    public function post(string $url, string $body, array $headers): array
    {
        return $this->record('POST', $url, $body, $headers);
    }

    /**
     * @param array<int, string> $headers
     * @return array{status:int, body:string}
     */
    private function record(string $method, string $url, string $body, array $headers): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers];

        $next = array_shift($this->responses);
        if ($next === null) {
            throw new RuntimeException('FakeHttp has no queued response for ' . $url);
        }

        return $next;
    }

    /** @return list<array{method:string, url:string, body:string, headers:array<int,string>}> */
    public function requests(): array
    {
        return $this->requests;
    }
}
