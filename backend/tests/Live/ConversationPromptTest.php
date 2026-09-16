<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Live\ConversationPrompt;
use PHPUnit\Framework\TestCase;

final class ConversationPromptTest extends TestCase
{
    public function testNewSessionGreetsImmediatelyAndStaysOnRentalHomes(): void
    {
        $text = ConversationPrompt::text(false, 'nl-NL');
        self::assertSame('conversation-v10', ConversationPrompt::version());
        self::assertStringContainsString('Begroet meteen', $text);
        self::assertStringContainsString('Schakel niet terug naar het Nederlands', $text);
        self::assertStringContainsString('app-schermen', $text);
        self::assertStringContainsString('voordat de bewoner ja zegt', $text);
        self::assertStringContainsString('Sierra Juliet', $text);
        self::assertStringContainsString('three five seven three', $text);
        self::assertStringContainsString('huurhuis', $text);
        self::assertStringContainsString('Vraag nooit of het een huur- of koopwoning is', $text);
        self::assertStringContainsString('vier cijfers en twee letters', $text);
        self::assertStringContainsString('drie vijf zeven drie', $text);
        self::assertStringContainsString('Simon Johan', $text);
        self::assertStringContainsString('adres afwijst', $text);
        self::assertStringContainsString('"Klopt"', $text);
        self::assertStringContainsString('bedank kort', $text);
        self::assertStringContainsString('later nog kan wijzigen', $text);
        self::assertStringContainsString('De melding is vastgelegd', $text);
        self::assertStringContainsString('gebouwtype is altijd een woning', $text);
        self::assertStringContainsString('één stem', $text);
        self::assertStringNotContainsString('koopwoning of huurwoning', $text);
    }
}
