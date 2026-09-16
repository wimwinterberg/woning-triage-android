<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Tree\TreeQuestionTranslations;
use PHPUnit\Framework\TestCase;

final class TreeQuestionTranslationsTest extends TestCase
{
    public function testGermanLocationQuestion(): void
    {
        self::assertSame(
            'Dans quelle pièce se trouve le problème ?',
            TreeQuestionTranslations::text('ask_location', 'fr-FR'),
        );
    }

    public function testUnknownLanguageFallsBack(): void
    {
        self::assertNull(TreeQuestionTranslations::text('ask_location', 'nl-NL'));
        self::assertNull(TreeQuestionTranslations::text('missing', 'de-DE'));
    }
}
