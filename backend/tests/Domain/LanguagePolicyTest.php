<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\LanguageMode;
use App\Domain\LanguagePolicy;
use PHPUnit\Framework\TestCase;

final class LanguagePolicyTest extends TestCase
{
    public function testOkayDoesNotSwitchLanguage(): void
    {
        $policy = new LanguagePolicy();
        $decision = $policy->detectFromResidentText('okay', 'nl-NL', LanguageMode::Auto);
        self::assertFalse($decision->changed);
        self::assertSame('nl-NL', $decision->language);
        self::assertSame('loanword', $decision->reason);
    }

    public function testClearEnglishSentenceSwitches(): void
    {
        $policy = new LanguagePolicy();
        $decision = $policy->detectFromResidentText(
            'The kitchen tap is leaking since yesterday because it is broken',
            'nl-NL',
            LanguageMode::Auto,
        );
        self::assertTrue($decision->changed);
        self::assertSame('en-GB', $decision->language);
    }

    public function testManualModeKeepsLanguage(): void
    {
        $policy = new LanguagePolicy();
        $decision = $policy->detectFromResidentText(
            'The kitchen tap is leaking since yesterday because it is broken',
            'nl-NL',
            LanguageMode::Manual,
        );
        self::assertFalse($decision->changed);
        self::assertSame('nl-NL', $decision->language);
    }

    public function testClearGermanSentenceSwitches(): void
    {
        $policy = new LanguagePolicy();
        $decision = $policy->detectFromResidentText(
            'Die Küche tropft seit gestern weil der Wasserhahn kaputt ist',
            'nl-NL',
            LanguageMode::Auto,
        );
        self::assertTrue($decision->changed);
        self::assertSame('de-DE', $decision->language);
    }

    public function testClearTurkishSentenceSwitches(): void
    {
        $policy = new LanguagePolicy();
        $decision = $policy->detectFromResidentText(
            'Mutfaktaki musluk bozuk çünkü sızıyor',
            'nl-NL',
            LanguageMode::Auto,
        );
        self::assertTrue($decision->changed);
        self::assertSame('tr-TR', $decision->language);
    }

    public function testClearJapaneseSentenceSwitches(): void
    {
        $policy = new LanguagePolicy();
        $decision = $policy->detectFromResidentText(
            'キッチンの蛇口が壊れています',
            'nl-NL',
            LanguageMode::Auto,
        );
        self::assertTrue($decision->changed);
        self::assertSame('ja-JP', $decision->language);
    }

    public function testStaysInEnglishOnceSwitched(): void
    {
        $policy = new LanguagePolicy();
        $decision = $policy->detectFromResidentText(
            'The kitchen tap is leaking since yesterday because it is broken',
            'en-GB',
            LanguageMode::Auto,
        );
        self::assertFalse($decision->changed);
        self::assertSame('en-GB', $decision->language);
    }

    public function testExplicitGermanRequest(): void
    {
        $policy = new LanguagePolicy();
        self::assertSame('de-DE', $policy->isExplicitLanguageRequest('Bitte auf Deutsch'));
        self::assertSame('tr-TR', $policy->isExplicitLanguageRequest('Türkçe konuş'));
        self::assertSame('ja-JP', $policy->isExplicitLanguageRequest('Please speak Japanese'));
        self::assertSame('pl-PL', $policy->isExplicitLanguageRequest('Please speak Polish'));
        self::assertSame('ar', $policy->isExplicitLanguageRequest('Speak Arabic'));
        self::assertSame('nl-NL', $policy->isExplicitLanguageRequest('Zet de interface naar het Nederlands'));
        self::assertTrue($policy->isUiSwitchRequest('Zet de interface naar het Nederlands'));
        self::assertFalse($policy->isUiSwitchRequest('Spreek Nederlands alsjeblieft'));
    }

    public function testClearPolishSentenceSwitches(): void
    {
        $policy = new LanguagePolicy();
        $decision = $policy->detectFromResidentText(
            'Kran w kuchni cieknie ponieważ jest zepsuty',
            'nl-NL',
            LanguageMode::Auto,
        );
        self::assertTrue($decision->changed);
        self::assertSame('pl-PL', $decision->language);
    }

    public function testArabicScriptSwitches(): void
    {
        $policy = new LanguagePolicy();
        $decision = $policy->detectFromResidentText(
            'الحنفية في المطبخ تسرب الماء',
            'nl-NL',
            LanguageMode::Auto,
        );
        self::assertTrue($decision->changed);
        self::assertSame('ar', $decision->language);
    }
}
