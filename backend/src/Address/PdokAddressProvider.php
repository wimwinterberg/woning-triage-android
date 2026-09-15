<?php

declare(strict_types=1);

namespace App\Address;

use App\Domain\IdGenerator;
use App\Exception\AddressLookupUnavailableException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Official Dutch PDOK Locatieserver v3.1 free search.
 * @see https://api.pdok.nl/bzk/locatieserver/search/v3_1/ui/
 */
final class PdokAddressProvider implements AddressProvider
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl = 'https://api.pdok.nl/bzk/locatieserver/search/v3_1/free',
    ) {
    }

    public function lookup(string $postcode, int $houseNumber, ?string $addition): array
    {
        $query = $postcode.' '.$houseNumber.($addition ? ' '.$addition : '');
        try {
            $response = $this->httpClient->request('GET', $this->baseUrl, [
                'query' => [
                    'q' => $query,
                    'fq' => 'type:adres',
                    'rows' => 10,
                    'wt' => 'json',
                ],
                'timeout' => 8,
            ]);
            $payload = $response->toArray(false);
        } catch (\Throwable) {
            throw new AddressLookupUnavailableException();
        }

        $docs = $payload['response']['docs'] ?? [];
        if (!is_array($docs)) {
            throw new AddressLookupUnavailableException();
        }

        $candidates = [];
        foreach ($docs as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $pc = strtoupper(trim((string) ($doc['postcode'] ?? '')));
            if ($pc !== '') {
                $pc = substr($pc, 0, 4).' '.substr($pc, 4);
            }
            $number = (int) ($doc['huisnummer'] ?? 0);
            $add = $this->composeAddition($doc);
            $street = (string) ($doc['straatnaam'] ?? '');
            $city = (string) ($doc['woonplaatsnaam'] ?? '');
            if ($street === '' || $city === '' || $number < 1) {
                continue;
            }
            $providerId = (string) ($doc['id'] ?? $doc['weergavenaam'] ?? $street.$number);
            $candidates[] = new AddressCandidate(
                candidateId: IdGenerator::prefixed('candidate'),
                postcode: $pc !== '' ? $pc : $postcode,
                houseNumber: $number,
                addition: $add,
                street: $street,
                city: $city,
                countryCode: 'NL',
                providerId: $providerId,
            );
        }

        return $candidates;
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function composeAddition(array $doc): ?string
    {
        $letter = trim((string) ($doc['huisletter'] ?? ''));
        $extra = trim((string) ($doc['huisnummertoevoeging'] ?? ''));
        $value = trim($letter.($extra !== '' ? $extra : ''));

        return $value === '' ? null : $value;
    }
}
