<?php

declare(strict_types=1);

namespace Mtmd\Ga4;

use InvalidArgumentException;

final class Tag
{
    /**
     * Renders the gtag.js snippet. Returns an empty string when the site has
     * no measurement ID configured, so callers can emit it unconditionally.
     *
     * The ID is validated against a strict pattern rather than escaped,
     * because it is interpolated into a JavaScript string literal where
     * HTML escaping would not be the right defence.
     */
    public static function render(string $measurementId): string
    {
        $id = trim($measurementId);
        if ($id === '') {
            return '';
        }

        if (!preg_match('/^G-[A-Z0-9]{6,20}$/i', $id)) {
            // $id failed validation, which means it is precisely the case
            // where it is arbitrary attacker-shaped text -- never interpolate
            // it raw into the message. A short sanitised excerpt keeps the
            // message useful without reopening what validation just closed.
            $safe = substr(preg_replace('/[^A-Za-z0-9_-]/', '', $id) ?? '', 0, 24);
            throw new InvalidArgumentException(
                'Not a GA4 measurement ID: ' . $safe . '. Expected the "G-XXXXXXXXXX" form '
                . '(the numeric property ID belongs in GA4_PROPERTY_ID).'
            );
        }

        $id = strtoupper($id);

        return <<<HTML
        <!-- Google tag (gtag.js) -->
        <script async src="https://www.googletagmanager.com/gtag/js?id={$id}"></script>
        <script>
          window.dataLayer = window.dataLayer || [];
          function gtag(){dataLayer.push(arguments);}
          gtag('js', new Date());

          gtag('config', '{$id}');
        </script>

        HTML;
    }
}
