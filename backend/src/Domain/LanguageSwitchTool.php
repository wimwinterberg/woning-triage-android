<?php

declare(strict_types=1);

namespace App\Domain;

use App\Entity\Intake;

/**
 * Backend tool invoked from GPT-Live client delegation.
 * GPT-Live client mode has no named function tools; this is the
 * application-owned switch_language operation.
 */
final class LanguageSwitchTool
{
    public const NAME = 'switch_language';

    public function __construct(
        public readonly string $language,
        public readonly bool $applyUi,
    ) {
    }

    public static function tryFromResidentText(string $text): ?self
    {
        $policy = new LanguagePolicy();
        $language = $policy->isExplicitLanguageRequest($text);
        if ($language === null) {
            return null;
        }

        return new self($language, $policy->isUiSwitchRequest($text));
    }

    public function apply(Intake $intake, IntakeDocument $document): void
    {
        $intake->setConversationLanguage($this->language);
        $document->invalidateSummary();
        if ($this->applyUi) {
            $document->uiLanguage = UiLanguages::uiTagForConversation($this->language);
            $document->uiLanguageOffer = null;
        }
    }
}
