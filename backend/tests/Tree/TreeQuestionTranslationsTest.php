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
            'In welchem Raum befindet sich das Problem?',
            TreeQuestionTranslations::text('ask_location', 'de-DE'),
        );
    }

    public function testUnknownLanguageFallsBack(): void
    {
        self::assertNull(TreeQuestionTranslations::text('ask_location', 'nl-NL'));
        self::assertNull(TreeQuestionTranslations::text('missing', 'de-DE'));
    }
}
