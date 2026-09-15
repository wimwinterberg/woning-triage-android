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
}
