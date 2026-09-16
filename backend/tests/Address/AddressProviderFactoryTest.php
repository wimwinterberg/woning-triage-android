<?php

declare(strict_types=1);

namespace App\Tests\Address;

use App\Address\AddressProviderFactory;
use App\Address\FakeAddressProvider;
use App\Address\PdokAddressProvider;
use App\Address\WcsAddressProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AddressProviderFactoryTest extends TestCase
{
    public function testSelectsConfiguredProvider(): void
    {
        $http = $this->createStub(HttpClientInterface::class);
        $wcs = new WcsAddressProvider($http, 'test-key');
        $pdok = new PdokAddressProvider($http);
        $fake = new FakeAddressProvider();

        self::assertSame($fake, AddressProviderFactory::create($wcs, $pdok, $fake, 'fake', 'test-key'));
        self::assertSame($pdok, AddressProviderFactory::create($wcs, $pdok, $fake, 'pdok', ''));
        self::assertSame($wcs, AddressProviderFactory::create($wcs, $pdok, $fake, 'pdok', 'test-key'));
        self::assertSame($wcs, AddressProviderFactory::create($wcs, $pdok, $fake, 'wcs', 'test-key'));
        self::assertSame($wcs, AddressProviderFactory::create($wcs, $pdok, $fake, null, 'test-key'));
        self::assertSame($wcs, AddressProviderFactory::create($wcs, $pdok, $fake, '', 'test-key'));
    }
}
