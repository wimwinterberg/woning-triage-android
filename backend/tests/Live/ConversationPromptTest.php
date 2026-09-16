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
        self::assertSame('conversation-v2', ConversationPrompt::version());
        self::assertStringContainsString('Begroet meteen', $text);
        self::assertStringContainsString('huurhuis', $text);
        self::assertStringContainsString('Vraag nooit of het een huur- of koopwoning is', $text);
        self::assertStringNotContainsString('koopwoning of huurwoning', $text);
    }
}
