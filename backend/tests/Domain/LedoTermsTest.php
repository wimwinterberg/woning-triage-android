<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\LedoTerms;
use PHPUnit\Framework\TestCase;

final class LedoTermsTest extends TestCase
{
    public function testKeepsDutchCanonicalValue(): void
    {
        self::assertSame('Keuken', LedoTerms::display('Keuken', 'nl-NL'));
    }

    public function testTranslatesKnownLabel(): void
    {
        self::assertSame('Kitchen', LedoTerms::display('Keuken', 'en-GB'));
        self::assertSame('Küche', LedoTerms::display('Keuken', 'de-DE'));
        self::assertSame('Mutfak', LedoTerms::display('Keuken', 'tr-TR'));
        self::assertSame('Cuisine', LedoTerms::display('Keuken', 'fr-FR'));
        self::assertSame('Kuchnia', LedoTerms::display('Keuken', 'pl-PL'));
    }

    public function testLeavesFreeTextAlone(): void
    {
        self::assertSame('boven bij de trap', LedoTerms::display('boven bij de trap', 'en-GB'));
    }
}
