<?php

declare(strict_types=1);

namespace App\Address;

use App\Http\OperationalLog;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Operational address-lookup logs without address PII.
 * Never writes STDOUT: php -S uses STDOUT as the HTTP body.
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
        try {
            $this->logger->info('Address lookup '.$event, $safe);
        } catch (\Throwable) {
        }
        if (($_SERVER['APP_ENV'] ?? '') === 'test') {
            return;
        }

        $parts = [];
        foreach ($safe as $key => $value) {
            $parts[] = $key.'='.$this->format($value);
        }
        $line = sprintf(
            'Address lookup %s%s',
            $event,
            $parts === [] ? '' : ' '.implode(' ', $parts),
        );
        OperationalLog::write($line);
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
