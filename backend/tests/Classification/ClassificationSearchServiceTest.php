<?php

declare(strict_types=1);

namespace App\Tests\Classification;

use App\Classification\ClassificationImporter;
use App\Classification\ClassificationSearchService;
use App\Domain\FieldName;
use App\Domain\FieldValue;
use App\Domain\IntakeDocument;
use App\Entity\Intake;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ClassificationSearchServiceTest extends KernelTestCase
{
    public function testSuggestsOnlyUnderWoningGebouwtype(): void
    {
        self::bootKernel();
        $importer = static::getContainer()->get(ClassificationImporter::class);
        $importer->import(dirname(__DIR__, 2).'/fixtures/classification/woning-versus-complex.json', 'woning-scope-1');

        $user = new User('user_class', 'tester');
        $document = IntakeDocument::initial(['id' => 'opening', 'target' => null, 'text' => 'vraag']);
        $document->setField(FieldName::Location, FieldValue::reported('Keuken', 'user_message', ['m1']));
        $document->setField(FieldName::Element, FieldValue::reported('Kraan', 'user_message', ['m1']));
        $document->setField(FieldName::Defect, FieldValue::reported('Lekt', 'user_message', ['m1']));
        $intake = new Intake('intake_class', $user, 'woning-scope-1', 'conversation-v6', $document);

        $matches = static::getContainer()->get(ClassificationSearchService::class)->suggest($intake, 10);
        self::assertCount(1, $matches);
        self::assertSame('def_lekt_woning', $matches[0]['source_value']);
        self::assertStringContainsString('gebouwtype:woning/', $matches[0]['path_key']);
        self::assertStringNotContainsString('complex', $matches[0]['path_key']);
    }
}
