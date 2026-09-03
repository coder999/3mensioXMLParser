<?php

declare(strict_types=1);

namespace Coder999\Ga4;

use Throwable;

final class Cli
{
    private const USAGE = <<<TXT
    usage: ga-provision <command> [options]

    Commands:
      list-accounts
          List the GA accounts this service account can see.

      create --account <id> --name <name> --domain <url> --timezone <tz> [--currency <code>]
          Create a GA4 property and a web data stream under it, then print
          the two .env lines the site needs. The time zone determines every
          future day boundary for this property and cannot be fixed up
          later without reprocessing, so it is required rather than
          defaulted.

    Reads the service account key from GA_SERVICE_ACCOUNT_JSON.
    Requires the analytics.edit scope and Editor on the GA account.
    TXT;

    public function __construct(
        private readonly HttpInterface $http,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @param list<string> $argv Arguments excluding the script name.
     * @param array<string, string> $env
     * @return array{code:int, out:string}
     */
    public function run(array $argv, array $env): array
    {
        $command = $argv[0] ?? '';
        if ($command !== 'list-accounts' && $command !== 'create') {
            return ['code' => 1, 'out' => self::USAGE . "\n"];
        }

        $key = trim($env['GA_SERVICE_ACCOUNT_JSON'] ?? '');
        if ($key === '') {
            return ['code' => 1, 'out' => "GA_SERVICE_ACCOUNT_JSON is not set.\n"];
        }

        try {
            $admin = new Admin(
                new TokenSource(
                    ServiceAccount::fromJson($key),
                    $this->cache,
                    $this->http,
                    TokenSource::SCOPE_EDIT,
                    'ga_admin_token_cache'
                ),
                $this->http
            );

            return $command === 'list-accounts'
                ? $this->listAccounts($admin)
                : $this->create($admin, self::options(array_slice($argv, 1)));
        } catch (Throwable $e) {
            return ['code' => 1, 'out' => 'Error: ' . $e->getMessage() . "\n"];
        }
    }

    /** @return array{code:int, out:string} */
    private function listAccounts(Admin $admin): array
    {
        $out = '';
        foreach ($admin->listAccounts() as $account) {
            $out .= $account['name'] . "\t" . $account['displayName'] . "\n";
        }

        return ['code' => 0, 'out' => $out === '' ? "No accounts visible to this service account.\n" : $out];
    }

    /**
     * @param array<string, string> $options
     * @return array{code:int, out:string}
     */
    private function create(Admin $admin, array $options): array
    {
        foreach (['account', 'name', 'domain', 'timezone'] as $required) {
            if (trim($options[$required] ?? '') === '') {
                return ['code' => 1, 'out' => 'Missing required option --' . $required . "\n\n" . self::USAGE . "\n"];
            }
        }

        $timezone = $options['timezone'];
        $currency = $options['currency'] ?? 'USD';

        $property = $admin->createProperty(
            $options['account'],
            $options['name'],
            $timezone,
            $currency
        );

        $stream = $admin->createWebDataStream(
            $property['propertyId'],
            $options['name'] . ' web',
            $options['domain']
        );

        $out = "Created {$property['name']} (timezone {$timezone}, currency {$currency}) and {$stream['name']}\n\n"
            . "Add these to the site's .env:\n"
            . "GA4_PROPERTY_ID={$property['propertyId']}\n"
            . "GA4_MEASUREMENT_ID={$stream['measurementId']}\n";

        return ['code' => 0, 'out' => $out];
    }

    /**
     * @param list<string> $argv
     * @return array<string, string>
     */
    private static function options(array $argv): array
    {
        $options = [];
        for ($i = 0; $i < count($argv); $i++) {
            if (str_starts_with($argv[$i], '--')) {
                $options[substr($argv[$i], 2)] = $argv[$i + 1] ?? '';
                $i++;
            }
        }

        return $options;
    }
}
