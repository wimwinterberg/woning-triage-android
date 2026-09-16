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

    public function testParsesSpokenDigitWords(): void
    {
        $parsed = DutchPostcodeParser::parse('Drie vijf zeven drie S J, Utrecht');
        self::assertSame('3573 SJ', $parsed['postcode']);
        self::assertNull($parsed['house_number']);
    }

    public function testParsesSpokenTensSplitBySpeechToText(): void
    {
        $parsed = DutchPostcodeParser::parse('Vijf dertig drieënzeventig Simon Johan');
        self::assertSame('3573 SJ', $parsed['postcode']);
        self::assertNull($parsed['house_number']);
    }

    public function testParsesZeventigAsZevenDrieWhenTwoUnitsPrecedeIt(): void
    {
        $parsed = DutchPostcodeParser::parse('Drie vijf zeventig Simon Johan in Utrecht');
        self::assertSame('3573 SJ', $parsed['postcode']);
        self::assertNull($parsed['house_number']);
    }

    public function testParsesSpokenHouseNumberHundreds(): void
    {
        $parsed = DutchPostcodeParser::parse('Drie vijf zeventig Simon Johan, tweehonderd zeven');
        self::assertSame('3573 SJ', $parsed['postcode']);
        self::assertSame(207, $parsed['house_number']);
    }

    public function testParsesCompoundSpokenTens(): void
    {
        $parsed = DutchPostcodeParser::parse('vijfendertig drieënzeventig simon johan huisnummer twaalf');
        self::assertSame('3573 SJ', $parsed['postcode']);
        self::assertSame(12, $parsed['house_number']);
    }

    public function testParsesSpokenDigitsIncludingZero(): void
    {
        $parsed = DutchPostcodeParser::parse('Vijfendertig drieënzeventig Simon Johan, twee nul zeven');
        self::assertSame('3573 SJ', $parsed['postcode']);
        self::assertSame(207, $parsed['house_number']);
    }

    public function testParsesSplitTensTwiceThenSpokenHouseDigits(): void
    {
        $parsed = DutchPostcodeParser::parse('vijf dertig drie zeventig Simon Johan twee nul zeven');
        self::assertSame('3573 SJ', $parsed['postcode']);
        self::assertSame(207, $parsed['house_number']);
    }

    public function testParsesSplitTensAfterSpokenFiller(): void
    {
        $parsed = DutchPostcodeParser::parse("Ik had 'm al gehoord. Vijf dertig drie zeventig Simon Johan twee nul zeven");
        self::assertSame('3573 SJ', $parsed['postcode']);
        self::assertSame(207, $parsed['house_number']);
    }

    public function testParsesGluedSpokenHundredsAsHouseNumber(): void
    {
        $parsed = DutchPostcodeParser::parse('Tweehonderdzeven');
        self::assertNull($parsed['postcode']);
        self::assertSame(207, $parsed['house_number']);
    }

    public function testParsesSpokenPostcodeAndGluedHundredsTogether(): void
    {
        $parsed = DutchPostcodeParser::parse('Vijfendertig drieënzeventig Simon Johan. Tweehonderdzeven');
        self::assertSame('3573 SJ', $parsed['postcode']);
        self::assertSame(207, $parsed['house_number']);
    }

    public function testExtractsStreetAndSingleAddressClaim(): void
    {
        $parsed = DutchPostcodeParser::parse('Er is maar één adres. Oldeburgstraat tweehonderd zeven');
        self::assertSame(207, $parsed['house_number']);
        self::assertSame('Oldeburgstraat', $parsed['street']);
        self::assertTrue(DutchPostcodeParser::claimsSingleAddress('Er is maar 1 adres. Oldeburgstraat tweehonderd zeven'));
    }

    public function testParsesUnitThenTensAsFourDigitPostcode(): void
    {
        $parsed = DutchPostcodeParser::parse('Nee. De postcode is acht zeven drie twintig Anton Johan drie honderd drieënnegentig');
        self::assertSame('8732 AJ', $parsed['postcode']);
        self::assertSame(393, $parsed['house_number']);
    }

    public function testParsesEnglishDigitWordsAndNatoLetters(): void
    {
        $parsed = DutchPostcodeParser::parse('The postcode is three five seven three Sierra Juliet house number two zero seven');
        self::assertSame('3573 SJ', $parsed['postcode']);
        self::assertSame(207, $parsed['house_number']);
    }

    public function testParsesEnglishTensThenUnits(): void
    {
        $parsed = DutchPostcodeParser::parse('thirty five seventy three S J two hundred and seven');
        self::assertSame('3573 SJ', $parsed['postcode']);
        self::assertSame(207, $parsed['house_number']);
    }

    public function testParsesEnglishJayForJ(): void
    {
        $parsed = DutchPostcodeParser::parse('three five seven three ess jay two oh seven');
        self::assertSame('3573 SJ', $parsed['postcode']);
        self::assertSame(207, $parsed['house_number']);
    }

    public function testParsesGermanDigitWords(): void
    {
        $parsed = DutchPostcodeParser::parse('Die Postleitzahl ist drei fünf sieben drei Sierra Juliet Hausnummer zwei null sieben');
        self::assertSame('3573 SJ', $parsed['postcode']);
        self::assertSame(207, $parsed['house_number']);
    }

    public function testParsesTurkishDigitWords(): void
    {
        $parsed = DutchPostcodeParser::parse('otuz beş yetmiş üç Sierra Juliet iki sifir yedi');
        self::assertSame('3573 SJ', $parsed['postcode']);
        self::assertSame(207, $parsed['house_number']);
    }

    public function testParsesJapaneseKanaDigits(): void
    {
        $parsed = DutchPostcodeParser::parse('さん ご なな さん Sierra Juliet に ゼロ なな');
        self::assertSame('3573 SJ', $parsed['postcode']);
        self::assertSame(207, $parsed['house_number']);
    }

    public function testEnglishOnlyOneAddressClaim(): void
    {
        self::assertTrue(DutchPostcodeParser::claimsSingleAddress('There is only one address'));
    }
}
