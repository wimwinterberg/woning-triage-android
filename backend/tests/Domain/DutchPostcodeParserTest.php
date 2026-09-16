<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\DutchPostcodeParser;
use PHPUnit\Framework\TestCase;

final class DutchPostcodeParserTest extends TestCase
{
    public function testParsesCompactAndSpacedPostcode(): void
    {
        self::assertSame('3573 SJ', DutchPostcodeParser::parse('3573SJ')['postcode']);
        self::assertSame('3573 SJ', DutchPostcodeParser::parse('3573 SJ')['postcode']);
        self::assertSame('3573 SJ', DutchPostcodeParser::parse('3573 S J')['postcode']);
    }

    public function testParsesSpelledLetterNames(): void
    {
        $parsed = DutchPostcodeParser::parse('3573 Simon Johan');
        self::assertSame('3573 SJ', $parsed['postcode']);
        self::assertNull($parsed['house_number']);
    }

    public function testParsesNamesWithHouseNumber(): void
    {
        $parsed = DutchPostcodeParser::parse('postcode 3573 simon johan huisnummer 12');
        self::assertSame('3573 SJ', $parsed['postcode']);
        self::assertSame(12, $parsed['house_number']);
    }

    public function testParsesStandaloneHouseNumberKeyword(): void
    {
        $parsed = DutchPostcodeParser::parse('huisnummer 18');
        self::assertNull($parsed['postcode']);
        self::assertSame(18, $parsed['house_number']);
    }

    public function testParsesGluedPostcodeAndHouseNumber(): void
    {
        $parsed = DutchPostcodeParser::parse('3573SJ12');
        self::assertSame('3573 SJ', $parsed['postcode']);
        self::assertSame(12, $parsed['house_number']);
    }

    public function testParsesSpelledLettersThenHouseNumber(): void
    {
        $parsed = DutchPostcodeParser::parse('3573 Simon Johan 12');
        self::assertSame('3573 SJ', $parsed['postcode']);
        self::assertSame(12, $parsed['house_number']);
    }

    public function testIgnoresBelgianFourDigitCodes(): void
    {
        $parsed = DutchPostcodeParser::parse('1000');
        self::assertNull($parsed['postcode']);
        self::assertNull($parsed['house_number']);
    }
}
