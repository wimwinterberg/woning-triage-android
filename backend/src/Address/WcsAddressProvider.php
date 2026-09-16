<?php

declare(strict_types=1);

namespace App\Address;

use App\Domain\AddressNormalizer;
use App\Domain\IdGenerator;
use App\Exception\AddressLookupUnavailableException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * We Create Solutions Address API. v1 supports Dutch (`nl`) addresses only.
 *
 * @see https://address-api.createsolutions.dev
 */
final class WcsAddressProvider implements AddressProvider
{
    private const COUNTRY = 'nl';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey = '',
        private readonly string $baseUrl = 'https://address-api.createsolutions.dev',
        private readonly AddressLookupLogger $lookupLogger = new AddressLookupLogger(),
    ) {
    }

    public function lookup(string $postcode, int $houseNumber, ?string $addition): array
    {
        $started = microtime(true);
        $host = parse_url($this->baseUrl, PHP_URL_HOST) ?: 'address-api.createsolutions.dev';
        $base = [
            'provider' => 'wcs',
            'host' => is_string($host) ? $host : 'address-api.createsolutions.dev',
            'path_template' => '/v1/postcode/{postalCode}/{houseNumber}',
            'has_api_key' => trim($this->apiKey) !== '',
            'compact_postcode_chars' => strlen(AddressNormalizer::compactPostcode($postcode)),
            'addition_filter' => $addition !== null && trim($addition) !== '',
        ];

        if (trim($this->apiKey) === '') {
            $this->lookupLogger->log('unavailable', $base + [
                'outcome' => 'missing_api_key',
                'duration_ms' => $this->elapsedMs($started),
            ]);
            throw new AddressLookupUnavailableException();
        }

        $compactPostcode = AddressNormalizer::compactPostcode($postcode);
        $url = rtrim($this->baseUrl, '/').'/v1/postcode/'.rawurlencode($compactPostcode).'/'.rawurlencode((string) $houseNumber);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Accept' => 'application/json',
                ],
                'timeout' => 8,
            ]);
            $status = $response->getStatusCode();
        } catch (\Throwable $exception) {
            $this->lookupLogger->log('unavailable', $base + [
                'outcome' => 'transport_error',
                'exception' => $exception::class,
                'duration_ms' => $this->elapsedMs($started),
            ]);
            throw new AddressLookupUnavailableException();
        }

        if ($status === 404 || $status === 400) {
            $this->lookupLogger->log('empty', $base + [
                'outcome' => $status === 404 ? 'not_found' : 'invalid_input',
                'http_status' => $status,
                'item_count' => 0,
                'candidate_count' => 0,
                'duration_ms' => $this->elapsedMs($started),
            ]);

            return [];
        }
        if ($status !== 200) {
            $this->lookupLogger->log('unavailable', $base + [
                'outcome' => 'http_error',
                'http_status' => $status,
                'duration_ms' => $this->elapsedMs($started),
            ]);
            throw new AddressLookupUnavailableException();
        }

        try {
            $payload = $response->toArray(false);
        } catch (\Throwable $exception) {
            $this->lookupLogger->log('unavailable', $base + [
                'outcome' => 'invalid_json',
                'http_status' => $status,
                'exception' => $exception::class,
                'duration_ms' => $this->elapsedMs($started),
            ]);
            throw new AddressLookupUnavailableException();
        }

        if (!array_is_list($payload)) {
            $this->lookupLogger->log('unavailable', $base + [
                'outcome' => 'payload_not_list',
                'http_status' => $status,
                'duration_ms' => $this->elapsedMs($started),
            ]);
            throw new AddressLookupUnavailableException();
        }

        $filter = $addition !== null && trim($addition) !== '' ? trim($addition) : null;
        $dropped = [
            'not_array' => 0,
            'incomplete' => 0,
            'house_number_mismatch' => 0,
            'invalid_postcode' => 0,
            'postcode_mismatch' => 0,
            'country_mismatch' => 0,
            'addition_mismatch' => 0,
        ];
        $candidates = [];
        foreach ($payload as $item) {
            if (!is_array($item)) {
                ++$dropped['not_array'];
                continue;
            }
            $mapped = $this->interpretItem($item, $postcode, $houseNumber);
            if (isset($mapped['drop'])) {
                $reason = $mapped['drop'];
                $dropped[$reason] = ($dropped[$reason] ?? 0) + 1;
                continue;
            }
            $candidate = $mapped['candidate'];
            if ($filter !== null && strcasecmp((string) $candidate->addition, $filter) !== 0) {
                ++$dropped['addition_mismatch'];
                continue;
            }
            $candidates[] = $candidate;
        }

        $withAddition = 0;
        foreach ($candidates as $candidate) {
            if ($candidate->addition !== null && $candidate->addition !== '') {
                ++$withAddition;
            }
        }

        $this->lookupLogger->log('finished', $base + [
            'outcome' => 'ok',
            'http_status' => $status,
            'item_count' => count($payload),
            'candidate_count' => count($candidates),
            'with_addition_count' => $withAddition,
            'dropped_not_array' => $dropped['not_array'],
            'dropped_incomplete' => $dropped['incomplete'],
            'dropped_house_number' => $dropped['house_number_mismatch'],
            'dropped_invalid_postcode' => $dropped['invalid_postcode'],
            'dropped_postcode' => $dropped['postcode_mismatch'],
            'dropped_country' => $dropped['country_mismatch'],
            'dropped_addition' => $dropped['addition_mismatch'],
            'duration_ms' => $this->elapsedMs($started),
        ]);

        return $candidates;
    }

    /**
     * @param array<string, mixed> $item
     * @return array{candidate: AddressCandidate}|array{drop: string}
     */
    private function interpretItem(array $item, string $requestedPostcode, int $requestedNumber): array
    {
        $street = trim((string) ($item['street'] ?? ''));
        $city = trim((string) ($item['city'] ?? ''));
        $number = (int) ($item['houseNumber'] ?? 0);
        if ($street === '' || $city === '' || $number < 1) {
            return ['drop' => 'incomplete'];
        }
        if ($number !== $requestedNumber) {
            return ['drop' => 'house_number_mismatch'];
        }

        $postalCode = trim((string) ($item['postalCode'] ?? ''));
        if ($postalCode === '') {
            $postalCode = $requestedPostcode;
        }
        $displayPostcode = AddressNormalizer::displayPostcode($postalCode);
        if ($displayPostcode === null) {
            return ['drop' => 'invalid_postcode'];
        }
        if (!AddressNormalizer::samePostcode($displayPostcode, $requestedPostcode)) {
            return ['drop' => 'postcode_mismatch'];
        }

        $country = strtolower(trim((string) ($item['country'] ?? self::COUNTRY)));
        if ($country === '') {
            $country = self::COUNTRY;
        }
        if ($country !== self::COUNTRY) {
            return ['drop' => 'country_mismatch'];
        }

        $addition = $this->composeAddition($item);
        $providerId = implode(':', [
            strtolower($country),
            $displayPostcode,
            (string) $number,
            trim((string) ($item['houseLetter'] ?? '')),
            trim((string) ($item['houseNumberAddition'] ?? '')),
            trim((string) ($item['unitNumber'] ?? '')),
        ]);

        return [
            'candidate' => new AddressCandidate(
                candidateId: IdGenerator::prefixed('candidate'),
                postcode: $displayPostcode,
                houseNumber: $number,
                addition: $addition,
                street: $street,
                city: $city,
                countryCode: strtoupper(self::COUNTRY),
                providerId: $providerId,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function composeAddition(array $item): ?string
    {
        $parts = [];
        foreach (['houseLetter', 'houseNumberAddition', 'unitNumber'] as $field) {
            $value = trim((string) ($item[$field] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
