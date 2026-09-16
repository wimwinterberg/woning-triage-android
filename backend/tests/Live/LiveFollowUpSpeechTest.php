<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Live\LiveFollowUpSpeech;
use PHPUnit\Framework\TestCase;

final class LiveFollowUpSpeechTest extends TestCase
{
    public function testDutchCommentaryDoesNotForceDutch(): void
    {
        $text = LiveFollowUpSpeech::commentary('In welke ruimte bevindt het probleem zich?', 'nl-NL', false);
        self::assertStringNotContainsString('in het Nederlands', $text);
        self::assertStringContainsString('do not switch back to Dutch', $text);
        self::assertStringContainsString('In welke ruimte', $text);
    }

    public function testEnglishCommentaryLocksEnglish(): void
    {
        $text = LiveFollowUpSpeech::commentary('In which room is the problem?', 'en-GB', false);
        self::assertStringContainsString('Speak only English', $text);
        self::assertStringContainsString('Do not speak Dutch', $text);
        self::assertStringContainsString('In which room is the problem?', $text);
    }

    public function testGermanCommentaryAsksForTranslation(): void
    {
        $text = LiveFollowUpSpeech::commentary('In welke ruimte bevindt het probleem zich?', 'de-DE', false);
        self::assertStringContainsString('Speak only German', $text);
        self::assertStringContainsString('Translate this meaning', $text);
        self::assertStringContainsString('Do not read it in Dutch', $text);
    }

    public function testLanguageSwitchStaysPut(): void
    {
        $text = LiveFollowUpSpeech::switchInstructions('ja-JP');
        self::assertStringContainsString('Japanese', $text);
        self::assertStringContainsString('Do not switch back to Dutch', $text);
    }
}
