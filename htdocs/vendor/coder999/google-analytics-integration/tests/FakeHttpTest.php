<?php

declare(strict_types=1);

namespace Coder999\Ga4\Tests;

use Coder999\Ga4\Tests\Support\FakeHttp;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FakeHttpTest extends TestCase
{
    public function testReturnsQueuedResponsesInOrderAndRecordsRequests(): void
    {
        $http = new FakeHttp();
        $http->queue(200, '{"first":true}');
        $http->queue(500, 'boom');

        $this->assertSame(
            ['status' => 200, 'body' => '{"first":true}'],
            $http->post('https://example.test/a', 'body-a', ['X-A: 1'])
        );
        $this->assertSame(
            ['status' => 500, 'body' => 'boom'],
            $http->post('https://example.test/b', 'body-b', [])
        );

        $requests = $http->requests();
        $this->assertCount(2, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('https://example.test/a', $requests[0]['url']);
        $this->assertSame('body-a', $requests[0]['body']);
        $this->assertSame(['X-A: 1'], $requests[0]['headers']);
    }

    public function testRecordsGetRequestsWithAnEmptyBody(): void
    {
        $http = new FakeHttp();
        $http->queue(200, '{"accounts":[]}');

        $this->assertSame(
            ['status' => 200, 'body' => '{"accounts":[]}'],
            $http->get('https://example.test/accounts', ['X-A: 1'])
        );

        $request = $http->requests()[0];
        $this->assertSame('GET', $request['method']);
        $this->assertSame('', $request['body']);
    }

    public function testThrowsWhenAnUnexpectedRequestIsMade(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no queued response');
        (new FakeHttp())->post('https://example.test/a', '', []);
    }
}
