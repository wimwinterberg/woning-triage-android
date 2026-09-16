<?php

declare(strict_types=1);

namespace App\Tests\Address;

use App\Address\AddressLookupLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AddressLookupLoggerTest extends TestCase
{
    public function testStripsAddressPiiFromContext(): void
    {
        $records = [];
        $psr = $this->createStub(LoggerInterface::class);
        $psr->method('info')->willReturnCallback(static function (string $message, array $context) use (&$records): void {
            $records[] = ['message' => $message, 'context' => $context];
        });

        (new AddressLookupLogger($psr))->log('finished', [
            'postcode' => '3573 SJ',
            'house_number' => 207,
            'street' => 'Oldenburgerstraat',
            'url' => 'https://address-api.createsolutions.dev/v1/postcode/3573SJ/207',
            'candidate_count' => 1,
            'http_status' => 200,
            'provider' => 'wcs',
        ]);

        self::assertCount(1, $records);
        self::assertSame('Address lookup finished', $records[0]['message']);
        self::assertSame(1, $records[0]['context']['candidate_count']);
        self::assertSame(200, $records[0]['context']['http_status']);
        self::assertSame('wcs', $records[0]['context']['provider']);
        self::assertArrayNotHasKey('postcode', $records[0]['context']);
        self::assertArrayNotHasKey('house_number', $records[0]['context']);
        self::assertArrayNotHasKey('street', $records[0]['context']);
        self::assertArrayNotHasKey('url', $records[0]['context']);
    }
}
