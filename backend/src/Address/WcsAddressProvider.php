<?php

declare(strict_types=1);

namespace App\Address;

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
    ) {
    }

    public function lookup(string $postcode, int $houseNumber, ?string $addition): array
    {
        if (trim($this->apiKey) === '') {
            throw new AddressLookupUnavailableException();
        }

        $compactPostcode = strtoupper(preg_replace('/\s+/', '', $postcode) ?? '');
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
        } catch (\Throwable) {
            throw new AddressLookupUnavailableException();
        }

        if ($status === 404 || $status === 400) {
            return [];
        }
        if ($status !== 200) {
            throw new AddressLookupUnavailableException();
        }

        try {
            $payload = $response->toArray(false);
        } catch (\Throwable) {
            throw new AddressLookupUnavailableException();
        }

        if (!array_is_list($payload)) {
            throw new AddressLookupUnavailableException();
        }

        $filter = $addition !== null && trim($addition) !== '' ? trim($addition) : null;
        $candidates = [];
        foreach ($payload as $item) {
            if (!is_array($item)) {
                continue;
            }
            $candidate = $this->mapItem($item, $postcode);
            if ($candidate === null) {
                continue;
            }
            if ($filter !== null && strcasecmp((string) $candidate->addition, $filter) !== 0) {
                continue;
            }
            $candidates[] = $candidate;
        }

        return $candidates;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function mapItem(array $item, string $fallbackPostcode): ?AddressCandidate
    {
        $street = trim((string) ($item['street'] ?? ''));
        $city = trim((string) ($item['city'] ?? ''));
        $number = (int) ($item['houseNumber'] ?? 0);
        if ($street === '' || $city === '' || $number < 1) {
            return null;
        }

        $postalCode = trim((string) ($item['postalCode'] ?? ''));
        if ($postalCode === '') {
            $postalCode = $fallbackPostcode;
        }

        $country = strtolower(trim((string) ($item['country'] ?? self::COUNTRY)));
        if ($country === '') {
            $country = self::COUNTRY;
        }
        if ($country !== self::COUNTRY) {
            return null;
        }

        $addition = $this->composeAddition($item);
        $providerId = implode(':', [
            strtolower($country),
            $postalCode,
            (string) $number,
            trim((string) ($item['houseLetter'] ?? '')),
            trim((string) ($item['houseNumberAddition'] ?? '')),
            trim((string) ($item['unitNumber'] ?? '')),
        ]);

        return new AddressCandidate(
            candidateId: IdGenerator::prefixed('candidate'),
            postcode: $postalCode,
            houseNumber: $number,
            addition: $addition,
            street: $street,
            city: $city,
            countryCode: strtoupper(self::COUNTRY),
            providerId: $providerId,
        );
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
}
