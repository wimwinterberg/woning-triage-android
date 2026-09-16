<?php

declare(strict_types=1);

namespace App\Address;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Operational address-lookup logs without address PII.
 * Writes to STDERR so `docker compose logs -f live-gateway api` shows them
 * without corrupting HTTP JSON from `php -S`.
 */
final class AddressLookupLogger
{
    private const BLOCKED = [
        'postcode', 'postalcode', 'postal_code', 'housenumber', 'house_number',
        'street', 'city', 'url', 'query', 'path', 'display_address', 'addition',
        'api_key', 'apikey', 'authorization', 'bearer', 'address', 'lat',
        'latitude', 'longitude', 'lng', 'municipality', 'province', 'nearby',
    ];

    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $event, array $context = []): void
    {
        $safe = $this->withoutPii($context);
        $this->logger->info('Address lookup '.$event, $safe);
        if (($_SERVER['APP_ENV'] ?? '') === 'test') {
            return;
        }

        $parts = [];
        foreach ($safe as $key => $value) {
            $parts[] = $key.'='.$this->format($value);
        }
        $line = sprintf(
            "[%s] Address lookup %s%s\n",
            gmdate('Y-m-d H:i:s'),
            $event,
            $parts === [] ? '' : ' '.implode(' ', $parts),
        );
        // Never STDOUT: php -S is CLI SAPI, so STDOUT is the HTTP body.
        fwrite(STDERR, $line);
        fflush(STDERR);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, bool|int|string>
     */
    private function withoutPii(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), self::BLOCKED, true)) {
                continue;
            }
            if (is_bool($value) || is_int($value)) {
                $safe[(string) $key] = $value;
                continue;
            }
            if (is_float($value) && is_finite($value)) {
                $safe[(string) $key] = (int) round($value);
                continue;
            }
            if (is_string($value) && $value !== '') {
                $safe[(string) $key] = $value;
            }
        }

        return $safe;
    }

    private function format(bool|int|string $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
