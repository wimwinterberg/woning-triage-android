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

    public function testGermanCommentaryUsesNativeTreeText(): void
    {
        $text = LiveFollowUpSpeech::commentary('In welchem Raum befindet sich das Problem?', 'de-DE', false);
        self::assertStringContainsString('Speak only German', $text);
        self::assertStringContainsString('Say this aloud', $text);
        self::assertStringContainsString('In welchem Raum', $text);
        self::assertStringContainsString('steady speaking speed', $text);
    }

    public function testAddressThankYouIsSpokenBeforeTheNextQuestion(): void
    {
        $text = LiveFollowUpSpeech::afterAddressVerified(
            'Dank u. Adres vastgelegd: Voorbeeldstraat 12, 1234 AB Amsterdam. U kunt het later nog wijzigen.',
            'In welke ruimte bevindt het probleem zich?',
            'nl-NL',
        );
        self::assertStringContainsString('do not skip it', $text);
        self::assertStringContainsString('Dank u. Adres vastgelegd', $text);
        self::assertStringContainsString('In welke ruimte', $text);
    }

    public function testLanguageSwitchStaysPut(): void
    {
        $text = LiveFollowUpSpeech::switchInstructions('ja-JP');
        self::assertStringContainsString('Japanese', $text);
        self::assertStringContainsString('Do not switch back to Dutch', $text);
    }

    public function testIdlePromptAsksForInput(): void
    {
        $text = LiveFollowUpSpeech::idlePrompt('nl-NL');
        self::assertStringContainsString('Bent u er nog?', $text);
        self::assertStringContainsString('Say this exactly', $text);
        self::assertSame('Bent u er nog? Ik wacht op uw antwoord.', LiveFollowUpSpeech::idlePromptSpoken('nl-NL'));
    }

    public function testIdleClosingAnnouncesShutdown(): void
    {
        $text = LiveFollowUpSpeech::idleClosing('en-GB');
        self::assertStringContainsString('closing the conversation', $text);
        self::assertStringContainsString('Speak only English', $text);
        self::assertSame(
            'It has gone quiet, so I am closing the conversation now. You can continue later.',
            LiveFollowUpSpeech::idleClosingSpoken('en-GB'),
        );
    }

    public function testUiSwitchConfirmationIsSpokenInEnglish(): void
    {
        $text = LiveFollowUpSpeech::afterUiLanguageSwitch('en-GB');
        self::assertStringContainsString('The app screens are now in English', $text);
        self::assertStringContainsString('Speak only English', $text);
        self::assertSame(
            'The app screens are now in English. You can change this later.',
            LiveFollowUpSpeech::afterUiLanguageSwitchSpoken('en-GB'),
        );
    }

    public function testUiOfferDoesNotClaimScreensChanged(): void
    {
        $text = LiveFollowUpSpeech::uiLanguageOffer(
            'Sie sprechen Deutsch. Soll ich die App-Bildschirme auch auf Deutsch umstellen?',
            'de-DE',
        );
        self::assertStringContainsString('Sie sprechen Deutsch', $text);
        self::assertStringContainsString('Do not say the app screens have already changed', $text);
    }

    public function testAnalysisRetryAsksTheResidentToRepeat(): void
    {
        $text = LiveFollowUpSpeech::analysisRetry('nl-NL');
        self::assertStringContainsString('korte storing', $text);
        self::assertSame(
            'Een moment, er was een korte storing. Zeg dat alstublieft nog een keer.',
            LiveFollowUpSpeech::analysisRetrySpoken('nl-NL'),
        );
    }
}
