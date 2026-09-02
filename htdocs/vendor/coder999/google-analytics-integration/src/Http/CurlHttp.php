<?php

declare(strict_types=1);

namespace Mtmd\Ga4\Http;

use Mtmd\Ga4\HttpInterface;
use RuntimeException;

/**
 * Deliberately thin: this is the only class in the package without a unit
 * test, because testing it would need a network. Keep logic out of it.
 */
final class CurlHttp implements HttpInterface
{
    public function __construct(private readonly int $timeoutSeconds = 15)
    {
    }

    public function get(string $url, array $headers): array
    {
        return $this->send($url, $headers, null);
    }

    public function post(string $url, string $body, array $headers): array
    {
        return $this->send($url, $headers, $body);
    }

    /**
     * @param array<int, string> $headers
     * @return array{status:int, body:string}
     */
    private function send(string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not initialise a curl handle.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
        ];
        if ($body !== null) {
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('HTTP request to ' . $url . ' failed: ' . $error);
        }

        return ['status' => $status, 'body' => (string) $response];
    }
}
