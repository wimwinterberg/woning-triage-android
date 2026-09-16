<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\IntakeDocument;
use App\Domain\LanguageSwitchTool;
use App\Entity\Intake;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class LanguageSwitchToolTest extends TestCase
{
    public function testSwitchToEnglishAppliesUi(): void
    {
        $tool = LanguageSwitchTool::tryFromResidentText('Switch to English');
        self::assertInstanceOf(LanguageSwitchTool::class, $tool);
        self::assertSame('en-GB', $tool->language);
        self::assertTrue($tool->applyUi);
        self::assertSame('switch_language', LanguageSwitchTool::NAME);

        $document = IntakeDocument::initial(['id' => 'q1', 'text' => 'In welke ruimte?']);
        $intake = new Intake('intake_test', new User('user_test', 'resident'), 'tree-v1', 'conversation-v12', $document);
        $tool->apply($intake, $document);
        self::assertSame('en-GB', $intake->getConversationLanguage());
        self::assertSame('en-GB', $document->uiLanguage);
        self::assertNull($document->uiLanguageOffer);
    }

    public function testSpeakEnglishDoesNotApplyUi(): void
    {
        $tool = LanguageSwitchTool::tryFromResidentText('Please speak English');
        self::assertInstanceOf(LanguageSwitchTool::class, $tool);
        self::assertSame('en-GB', $tool->language);
        self::assertFalse($tool->applyUi);
    }

    public function testInterfacePhraseAppliesUi(): void
    {
        $tool = LanguageSwitchTool::tryFromResidentText('Switch the interface to English');
        self::assertInstanceOf(LanguageSwitchTool::class, $tool);
        self::assertSame('en-GB', $tool->language);
        self::assertTrue($tool->applyUi);
    }

    public function testOrdinaryLeakSentenceIsNotAToolCall(): void
    {
        self::assertNull(LanguageSwitchTool::tryFromResidentText(
            'The kitchen tap is leaking since yesterday because it is broken',
        ));
    }
}
