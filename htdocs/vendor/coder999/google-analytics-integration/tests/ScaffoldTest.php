<?php

declare(strict_types=1);

namespace Coder999\Ga4\Tests;

use Coder999\Ga4\Version;
use PHPUnit\Framework\TestCase;

final class ScaffoldTest extends TestCase
{
    public function testAutoloadingResolvesThePackageNamespace(): void
    {
        $this->assertSame('0.1.0', Version::CURRENT);
    }
}
