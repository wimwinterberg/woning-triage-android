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
        self::assertSame('conversation-v5', ConversationPrompt::version());
        self::assertStringContainsString('Begroet meteen', $text);
        self::assertStringContainsString('huurhuis', $text);
        self::assertStringContainsString('Vraag nooit of het een huur- of koopwoning is', $text);
        self::assertStringContainsString('vier cijfers en twee letters', $text);
        self::assertStringContainsString('drie vijf zeven drie', $text);
        self::assertStringContainsString('Simon Johan', $text);
        self::assertStringContainsString('adres afwijst', $text);
        self::assertStringNotContainsString('koopwoning of huurwoning', $text);
    }
}
