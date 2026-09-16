<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\AddressNormalizer;
use PHPUnit\Framework\TestCase;

final class AddressNormalizerTest extends TestCase
{
    public function testNormalizesDutchPostcode(): void
    {
        $normalizer = new AddressNormalizer();
        self::assertSame('1234 AB', $normalizer->normalizePostcode('1234ab'));
        self::assertSame('1234 AB', $normalizer->normalizePostcode('1234 AB'));
    }

    public function testRejectsBelgianAndInvalidPostcodes(): void
    {
        $normalizer = new AddressNormalizer();
        $this->expectException(\InvalidArgumentException::class);
        $normalizer->normalizePostcode('1000');
    }
}
