<?php

declare(strict_types=1);

namespace App\Address;

use App\Domain\AddressNormalizer;
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
        private readonly AddressLookupLogger $lookupLogger = new AddressLookupLogger(),
    ) {
    }

    public function lookup(string $postcode, int $houseNumber, ?string $addition): array
    {
        $started = microtime(true);
        $host = parse_url($this->baseUrl, PHP_URL_HOST) ?: 'api.pdok.nl';
        $base = [
            'provider' => 'pdok',
            'host' => is_string($host) ? $host : 'api.pdok.nl',
            'path_template' => '/bzk/locatieserver/search/v3_1/free',
            'addition_filter' => $addition !== null && trim($addition) !== '',
        ];
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
            $status = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (\Throwable $exception) {
            $this->lookupLogger->log('unavailable', $base + [
                'outcome' => 'transport_error',
                'exception' => $exception::class,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
            throw new AddressLookupUnavailableException();
        }

        $docs = $payload['response']['docs'] ?? [];
        if (!is_array($docs)) {
            $this->lookupLogger->log('unavailable', $base + [
                'outcome' => 'payload_not_list',
                'http_status' => $status,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
            throw new AddressLookupUnavailableException();
        }

        $droppedPostcode = 0;
        $droppedNumber = 0;
        $candidates = [];
        foreach ($docs as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $pc = AddressNormalizer::displayPostcode((string) ($doc['postcode'] ?? '')) ?? $postcode;
            $number = (int) ($doc['huisnummer'] ?? 0);
            if ($number !== $houseNumber) {
                ++$droppedNumber;
                continue;
            }
            if (!AddressNormalizer::samePostcode($pc, $postcode)) {
                ++$droppedPostcode;
                continue;
            }
            $add = $this->composeAddition($doc);
            $street = (string) ($doc['straatnaam'] ?? '');
            $city = (string) ($doc['woonplaatsnaam'] ?? '');
            if ($street === '' || $city === '' || $number < 1) {
                continue;
            }
            $providerId = (string) ($doc['id'] ?? $doc['weergavenaam'] ?? $street.$number);
            $candidates[] = new AddressCandidate(
                candidateId: IdGenerator::prefixed('candidate'),
                postcode: $pc,
                houseNumber: $number,
                addition: $add,
                street: $street,
                city: $city,
                countryCode: 'NL',
                providerId: $providerId,
            );
        }

        $this->lookupLogger->log('finished', $base + [
            'outcome' => 'ok',
            'http_status' => $status,
            'item_count' => count($docs),
            'candidate_count' => count($candidates),
            'dropped_postcode' => $droppedPostcode,
            'dropped_house_number' => $droppedNumber,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

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
