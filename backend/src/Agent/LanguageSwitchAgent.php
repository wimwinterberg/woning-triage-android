<?php

declare(strict_types=1);

namespace App\Agent;

use App\Domain\LanguageSwitchTool;

/**
 * Backend agent that may invoke the switch_language function tool.
 */
interface LanguageSwitchAgent
{
    public function decide(string $text, string $conversationLanguage, ?string $uiLanguage): ?LanguageSwitchTool;
}
