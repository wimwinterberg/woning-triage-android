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
        self::assertTrue(AddressNormalizer::samePostcode('3573SJ', '3573 SJ'));
        self::assertFalse(AddressNormalizer::samePostcode('3573 SJ', '3511 AB'));
        self::assertSame('3573 SJ', AddressNormalizer::displayPostcode('3573sj'));
    }

    public function testRejectsBelgianAndInvalidPostcodes(): void
    {
        $normalizer = new AddressNormalizer();
        $this->expectException(\InvalidArgumentException::class);
        $normalizer->normalizePostcode('1000');
    }
}
