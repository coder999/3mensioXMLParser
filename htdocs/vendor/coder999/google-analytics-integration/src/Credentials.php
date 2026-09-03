<?php

declare(strict_types=1);

namespace Coder999\Ga4;

use InvalidArgumentException;

final class Credentials
{
    public readonly string $propertyId;

    public function __construct(
        public readonly ServiceAccount $account,
        string $propertyId,
    ) {
        $normalised = preg_replace('#^properties/#', '', trim($propertyId)) ?? '';
        if (!preg_match('/^\d+$/', $normalised)) {
            throw new InvalidArgumentException(
                'GA4 property ID must be numeric (for example 123456789). '
                . 'It is not the "G-XXXX" measurement ID, which belongs in GA4_MEASUREMENT_ID.'
            );
        }

        $this->propertyId = $normalised;
    }
}
