<?php

declare(strict_types=1);

namespace App\Agent;

use App\Domain\LanguageSwitchTool;

/**
 * Test double. Production without OPENAI_API_KEY also uses this as a no-op.
 * It never inspects the transcript; tests enqueue a tool call the model would have made.
 */
final class FakeLanguageSwitchAgent implements LanguageSwitchAgent
{
    /** @var list<LanguageSwitchTool|null> */
    private static array $queue = [];

    public function enqueue(?LanguageSwitchTool $tool): void
    {
        self::$queue[] = $tool;
    }

    public function reset(): void
    {
        self::$queue = [];
    }

    public function decide(string $text, string $conversationLanguage, ?string $uiLanguage, bool $offerPending = false): ?LanguageSwitchTool
    {
        if (self::$queue === []) {
            return null;
        }

        return array_shift(self::$queue);
    }
}
