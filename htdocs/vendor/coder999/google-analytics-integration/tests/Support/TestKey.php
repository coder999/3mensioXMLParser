<?php

declare(strict_types=1);

namespace Mtmd\Ga4\Tests\Support;

use RuntimeException;

/** A real RSA keypair, generated once per process, so RS256 assertions are real. */
final class TestKey
{
    private static ?string $pem = null;
    private static ?string $publicKey = null;

    public static function pem(): string
    {
        self::generate();

        return (string) self::$pem;
    }

    public static function publicKey(): string
    {
        self::generate();

        return (string) self::$publicKey;
    }

    public static function serviceAccountJson(string $email = 'ga-reader@example.iam.gserviceaccount.com'): string
    {
        return json_encode(
            ['client_email' => $email, 'private_key' => self::pem()],
            JSON_THROW_ON_ERROR
        );
    }

    private static function generate(): void
    {
        if (self::$pem !== null) {
            return;
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false) {
            throw new RuntimeException('Could not generate a test RSA key.');
        }

        openssl_pkey_export($resource, $pem);
        $details = openssl_pkey_get_details($resource);

        self::$pem       = (string) $pem;
        self::$publicKey = (string) ($details['key'] ?? '');
    }
}
