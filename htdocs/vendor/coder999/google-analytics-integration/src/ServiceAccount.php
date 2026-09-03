<?php

declare(strict_types=1);

namespace Coder999\Ga4;

use InvalidArgumentException;

final class ServiceAccount
{
    private function __construct(
        public readonly string $clientEmail,
        public readonly string $privateKey,
    ) {
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode(trim($json), true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Service account key is not valid JSON.');
        }
        if (empty($decoded['client_email']) || empty($decoded['private_key'])) {
            throw new InvalidArgumentException(
                'Service account key is missing client_email or private_key.'
            );
        }

        return new self((string) $decoded['client_email'], (string) $decoded['private_key']);
    }
}
