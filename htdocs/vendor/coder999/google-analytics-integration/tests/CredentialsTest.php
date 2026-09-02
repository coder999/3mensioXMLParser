<?php

declare(strict_types=1);

namespace Mtmd\Ga4\Tests;

use InvalidArgumentException;
use Mtmd\Ga4\Credentials;
use Mtmd\Ga4\ServiceAccount;
use PHPUnit\Framework\TestCase;

final class CredentialsTest extends TestCase
{
    private function validJson(): string
    {
        return json_encode([
            'client_email' => 'ga-reader@example.iam.gserviceaccount.com',
            'private_key'  => "-----BEGIN PRIVATE KEY-----\nstub\n-----END PRIVATE KEY-----\n",
        ], JSON_THROW_ON_ERROR);
    }

    public function testParsesAValidServiceAccountJson(): void
    {
        $account = ServiceAccount::fromJson($this->validJson());
        $this->assertSame('ga-reader@example.iam.gserviceaccount.com', $account->clientEmail);
        $this->assertStringContainsString('BEGIN PRIVATE KEY', $account->privateKey);
    }

    public function testRejectsMalformedJson(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not valid JSON');
        ServiceAccount::fromJson('{nope');
    }

    public function testRejectsJsonMissingThePrivateKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('client_email or private_key');
        ServiceAccount::fromJson(json_encode(['client_email' => 'a@b.c'], JSON_THROW_ON_ERROR));
    }

    public function testAcceptsANumericPropertyId(): void
    {
        $creds = new Credentials(ServiceAccount::fromJson($this->validJson()), '123456789');
        $this->assertSame('123456789', $creds->propertyId);
    }

    public function testStripsAPropertiesPrefixAndSurroundingWhitespace(): void
    {
        $creds = new Credentials(ServiceAccount::fromJson($this->validJson()), '  properties/123456789 ');
        $this->assertSame('123456789', $creds->propertyId);
    }

    public function testRejectsAMeasurementIdUsedAsAPropertyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('G-XXXX');
        new Credentials(ServiceAccount::fromJson($this->validJson()), 'G-ABC1234567');
    }

    public function testRejectsAnEmptyPropertyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Credentials(ServiceAccount::fromJson($this->validJson()), '   ');
    }
}
