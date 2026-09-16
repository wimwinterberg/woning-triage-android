<?php

declare(strict_types=1);

namespace App\Tests\Address;

use App\Address\AddressLookupLogger;
use App\Address\WcsAddressProvider;
use App\Exception\AddressLookupUnavailableException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class WcsAddressProviderTest extends TestCase
{
    public function testMapsSuccessfulLookupAndSendsBearerToken(): void
    {
        $response = $this->response(200, [
            [
                'country' => 'nl',
                'postalCode' => '1071 BM',
                'houseNumber' => 10,
                'houseLetter' => null,
                'houseNumberAddition' => null,
                'unitNumber' => null,
                'street' => 'Museumplein',
                'city' => 'Amsterdam',
                'municipality' => 'Amsterdam',
                'province' => 'Noord-Holland',
                'region' => null,
                'latitude' => 52.358,
                'longitude' => 4.878,
            ],
        ]);
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())
            ->method('request')
            ->with(
                'GET',
                'https://address-api.createsolutions.dev/v1/postcode/1071BM/10',
                self::callback(static function (array $options): bool {
                    return ($options['headers']['Authorization'] ?? null) === 'Bearer test-key'
                        && ($options['headers']['Accept'] ?? null) === 'application/json';
                }),
            )
            ->willReturn($response);

        $candidates = (new WcsAddressProvider($http, 'test-key'))->lookup('1071 BM', 10, null);

        self::assertCount(1, $candidates);
        self::assertSame('1071 BM', $candidates[0]->postcode);
        self::assertSame(10, $candidates[0]->houseNumber);
        self::assertNull($candidates[0]->addition);
        self::assertSame('Museumplein', $candidates[0]->street);
        self::assertSame('Amsterdam', $candidates[0]->city);
        self::assertSame('NL', $candidates[0]->countryCode);
        self::assertSame('nl:1071 BM:10:::', $candidates[0]->providerId);
        self::assertStringStartsWith('candidate_', $candidates[0]->candidateId);
    }

    public function testReturnsMultipleUnitsAndFiltersAddition(): void
    {
        $docs = [
            $this->unit('A'),
            $this->unit('B'),
        ];
        $all = (new WcsAddressProvider($this->httpReturning(200, $docs), 'test-key'))->lookup('1012 JS', 5, null);
        self::assertCount(2, $all);
        self::assertSame(['A', 'B'], array_map(static fn ($candidate) => $candidate->addition, $all));

        $filtered = (new WcsAddressProvider($this->httpReturning(200, $docs), 'test-key'))->lookup('1012 JS', 5, 'A');
        self::assertCount(1, $filtered);
        self::assertSame('A', $filtered[0]->addition);
        self::assertSame('Damrak', $filtered[0]->street);
    }

    public function testComposesLetterAndAdditionForDutchUnits(): void
    {
        $provider = new WcsAddressProvider($this->httpReturning(200, [[
            'country' => 'nl',
            'postalCode' => '1012 JS',
            'houseNumber' => 5,
            'houseLetter' => 'A',
            'houseNumberAddition' => 'bis',
            'unitNumber' => null,
            'street' => 'Damrak',
            'city' => 'Amsterdam',
        ]]), 'test-key');

        $candidates = $provider->lookup('1012 JS', 5, null);
        self::assertCount(1, $candidates);
        self::assertSame('A bis', $candidates[0]->addition);
        self::assertSame('NL', $candidates[0]->countryCode);
        self::assertSame('nl:1012 JS:5:A:bis:', $candidates[0]->providerId);
    }

    public function testIgnoresNonDutchResults(): void
    {
        $candidates = (new WcsAddressProvider($this->httpReturning(200, [
            [
                'country' => 'be',
                'postalCode' => '1000',
                'houseNumber' => 1,
                'houseLetter' => null,
                'houseNumberAddition' => null,
                'unitNumber' => null,
                'street' => 'Rue de la Loi',
                'city' => 'Bruxelles',
            ],
            [
                'country' => 'nl',
                'postalCode' => '1071 BM',
                'houseNumber' => 10,
                'houseLetter' => null,
                'houseNumberAddition' => null,
                'unitNumber' => null,
                'street' => 'Museumplein',
                'city' => 'Amsterdam',
            ],
        ]), 'test-key'))->lookup('1071 BM', 10, null);

        self::assertCount(1, $candidates);
        self::assertSame('NL', $candidates[0]->countryCode);
        self::assertSame('Museumplein', $candidates[0]->street);
    }

    public function testKeepsOnlyRequestedPostcodeAndHouseNumber(): void
    {
        $candidates = (new WcsAddressProvider($this->httpReturning(200, [
            [
                'country' => 'nl',
                'postalCode' => '3573 SJ',
                'houseNumber' => 207,
                'houseLetter' => null,
                'houseNumberAddition' => null,
                'unitNumber' => null,
                'street' => 'Oldenburgerstraat',
                'city' => 'Utrecht',
            ],
            [
                'country' => 'nl',
                'postalCode' => '3511 AB',
                'houseNumber' => 207,
                'houseLetter' => null,
                'houseNumberAddition' => null,
                'unitNumber' => null,
                'street' => 'Andere straat',
                'city' => 'Utrecht',
            ],
            [
                'country' => 'nl',
                'postalCode' => '3573SJ',
                'houseNumber' => 209,
                'houseLetter' => null,
                'houseNumberAddition' => null,
                'unitNumber' => null,
                'street' => 'Oldenburgerstraat',
                'city' => 'Utrecht',
            ],
        ]), 'test-key'))->lookup('3573 SJ', 207, null);

        self::assertCount(1, $candidates);
        self::assertSame('Oldenburgerstraat', $candidates[0]->street);
        self::assertSame('3573 SJ', $candidates[0]->postcode);
        self::assertSame(207, $candidates[0]->houseNumber);
        self::assertNull($candidates[0]->addition);
    }

    public function testLogsLookupOutcomeWithoutAddressPii(): void
    {
        $records = [];
        $psr = $this->createStub(LoggerInterface::class);
        $psr->method('info')->willReturnCallback(static function (string $message, array $context) use (&$records): void {
            $records[] = ['message' => $message, 'context' => $context];
        });

        $candidates = (new WcsAddressProvider(
            $this->httpReturning(200, [[
                'country' => 'nl',
                'postalCode' => '3573 SJ',
                'houseNumber' => 207,
                'street' => 'Oldenburgerstraat',
                'city' => 'Utrecht',
            ]]),
            'test-key',
            'https://address-api.createsolutions.dev',
            new AddressLookupLogger($psr),
        ))->lookup('3573 SJ', 207, null);

        self::assertCount(1, $candidates);
        self::assertNotEmpty($records);
        $finished = null;
        foreach ($records as $record) {
            if (str_contains($record['message'], 'finished')) {
                $finished = $record;
                break;
            }
        }
        self::assertNotNull($finished);
        self::assertSame('wcs', $finished['context']['provider']);
        self::assertSame(200, $finished['context']['http_status']);
        self::assertSame(1, $finished['context']['item_count']);
        self::assertSame(1, $finished['context']['candidate_count']);
        self::assertSame(6, $finished['context']['compact_postcode_chars']);
        self::assertArrayNotHasKey('postcode', $finished['context']);
        self::assertArrayNotHasKey('url', $finished['context']);
        self::assertArrayNotHasKey('street', $finished['context']);
        self::assertArrayNotHasKey('house_number', $finished['context']);
    }

    public function testNotFoundReturnsEmptyList(): void
    {
        $candidates = (new WcsAddressProvider($this->httpReturning(404, [
            'errorCode' => 'not_found',
            'error' => 'Address was not found',
        ]), 'test-key'))->lookup('1234 AB', 99999, null);

        self::assertSame([], $candidates);
    }

    public function testInvalidInputReturnsEmptyList(): void
    {
        $candidates = (new WcsAddressProvider($this->httpReturning(400, [
            'errorCode' => 'invalid_postal_code',
            'error' => 'postalCode is invalid',
        ]), 'test-key'))->lookup('1234 AB', 10, null);

        self::assertSame([], $candidates);
    }

    #[DataProvider('unavailableStatuses')]
    public function testProviderErrorsAreUnavailable(int $status): void
    {
        $this->expectException(AddressLookupUnavailableException::class);
        (new WcsAddressProvider($this->httpReturning($status, [
            'errorCode' => 'unauthorized',
            'error' => 'Unauthorized',
        ]), 'test-key'))->lookup('1234 AB', 10, null);
    }

    /**
     * @return list<list<int>>
     */
    public static function unavailableStatuses(): array
    {
        return [[401], [429], [503], [500]];
    }

    public function testMissingApiKeyDoesNotCallHttp(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::never())->method('request');

        $this->expectException(AddressLookupUnavailableException::class);
        (new WcsAddressProvider($http, ''))->lookup('1234 AB', 12, null);
    }

    public function testTransportFailureIsUnavailable(): void
    {
        $http = $this->createStub(HttpClientInterface::class);
        $http->method('request')->willThrowException(new \RuntimeException('timeout'));

        $this->expectException(AddressLookupUnavailableException::class);
        (new WcsAddressProvider($http, 'test-key'))->lookup('1234 AB', 12, null);
    }

    /**
     * @param list<array<string, mixed>>|array<string, mixed> $payload
     */
    private function httpReturning(int $status, array $payload): HttpClientInterface
    {
        $http = $this->createStub(HttpClientInterface::class);
        $http->method('request')->willReturn($this->response($status, $payload));

        return $http;
    }

    /**
     * @param list<array<string, mixed>>|array<string, mixed> $payload
     */
    private function response(int $status, array $payload): ResponseInterface
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('toArray')->willReturn($payload);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function unit(string $letter): array
    {
        return [
            'country' => 'nl',
            'postalCode' => '1012 JS',
            'houseNumber' => 5,
            'houseLetter' => $letter,
            'houseNumberAddition' => null,
            'unitNumber' => null,
            'street' => 'Damrak',
            'city' => 'Amsterdam',
        ];
    }
}
