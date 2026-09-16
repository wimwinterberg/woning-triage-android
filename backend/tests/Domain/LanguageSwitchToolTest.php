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
    public function testSchemaIsANamedFunctionTool(): void
    {
        $schema = LanguageSwitchTool::schema();
        self::assertSame('function', $schema['type']);
        self::assertSame('switch_language', $schema['name']);
        self::assertSame(['language', 'apply_ui'], $schema['parameters']['required']);
        self::assertContains('en-GB', $schema['parameters']['properties']['language']['enum']);
        self::assertContains('nl-NL', $schema['parameters']['properties']['language']['enum']);
    }

    public function testParsesResponsesFunctionCallAndAppliesUi(): void
    {
        $tool = LanguageSwitchTool::tryFromModelOutput([
            'output' => [[
                'type' => 'function_call',
                'name' => 'switch_language',
                'arguments' => '{"language":"en-GB","apply_ui":true}',
            ]],
        ]);
        self::assertInstanceOf(LanguageSwitchTool::class, $tool);
        self::assertSame('en-GB', $tool->language);
        self::assertTrue($tool->applyUi);

        $document = IntakeDocument::initial(['id' => 'q1', 'text' => 'In welke ruimte?']);
        $intake = new Intake('intake_test', new User('user_test', 'resident'), 'tree-v1', 'conversation-v13', $document);
        $tool->apply($intake, $document);
        self::assertSame('en-GB', $intake->getConversationLanguage());
        self::assertSame('en-GB', $document->uiLanguage);
        self::assertNull($document->uiLanguageOffer);
    }

    public function testSpeakEnglishStyleCallDoesNotApplyUi(): void
    {
        $tool = LanguageSwitchTool::tryFromCall('switch_language', [
            'language' => 'en-GB',
            'apply_ui' => false,
        ]);
        self::assertInstanceOf(LanguageSwitchTool::class, $tool);
        self::assertFalse($tool->applyUi);
    }

    public function testIgnoresTextOutputWithoutAToolCall(): void
    {
        self::assertNull(LanguageSwitchTool::tryFromModelOutput([
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => 'Switch to English']],
            ]],
        ]));
    }

    public function testIgnoresUnknownToolName(): void
    {
        self::assertNull(LanguageSwitchTool::tryFromCall('other_tool', [
            'language' => 'en-GB',
            'apply_ui' => true,
        ]));
    }
}
