<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\UiLanguages;
use PHPUnit\Framework\TestCase;

final class UiLanguagesTest extends TestCase
{
    public function testCatalogIncludesMigrantLanguagesAndJapanese(): void
    {
        $tags = UiLanguages::tags();
        foreach (['nl-NL', 'en-GB', 'de-DE', 'fr-FR', 'es-ES', 'tr-TR', 'ar', 'pl-PL', 'pap', 'zgh', 'ja-JP'] as $tag) {
            self::assertContains($tag, $tags);
        }
    }

    public function testUnsupportedConversationOffersEnglishUi(): void
    {
        self::assertSame('en-GB', UiLanguages::uiTagForConversation('it-IT'));
        self::assertSame('pl-PL', UiLanguages::uiTagForConversation('pl'));
        self::assertSame('ar', UiLanguages::uiTagForConversation('ar-SA'));
    }

    public function testOfferAsksBeforeSwitching(): void
    {
        $question = UiLanguages::offerQuestion(['language' => 'en-GB', 'reason' => 'detected'], 'en-GB');
        self::assertStringContainsString('English', $question);
        self::assertStringContainsString('screens', $question);
        $unsupported = UiLanguages::offerQuestion(['language' => 'en-GB', 'reason' => 'unsupported'], 'nl-NL');
        self::assertStringContainsString('Engels', $unsupported);
    }
}
