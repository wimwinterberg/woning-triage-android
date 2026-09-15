<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\FieldName;
use App\Domain\FieldState;
use App\Domain\FieldValue;
use App\Domain\IntakeDocument;
use PHPUnit\Framework\TestCase;

final class IntakeDocumentTest extends TestCase
{
    public function testUnknownCauseDoesNotBlockLedoReady(): void
    {
        $document = IntakeDocument::initial(null);
        $document->setField(FieldName::Location, FieldValue::reported('Keuken', 'user_message', ['m1']));
        $document->setField(FieldName::Element, FieldValue::reported('Kraan', 'user_message', ['m1']));
        $document->setField(FieldName::Defect, FieldValue::reported('Druppelt', 'user_message', ['m1']));
        $document->setField(FieldName::Cause, FieldValue::unknown('user_message', ['m2']));
        self::assertTrue($document->ledoReadyForAddress());
        self::assertSame([], $document->incompleteFieldIds());
    }

    public function testLocationCorrectionMarksDependentFields(): void
    {
        $document = IntakeDocument::initial(null);
        $document->setField(FieldName::Location, FieldValue::reported('Keuken', 'user_message', ['m1']));
        $document->setField(FieldName::Element, FieldValue::reported('Kraan', 'user_message', ['m1']));
        $document->setField(FieldName::Defect, FieldValue::reported('Druppelt sinds gisteren', 'user_message', ['m1']));
        $document->addIndependentAnswer('observed_since', 'sinds gisteren', 'm1');
        $document->applyUserCorrections([[
            'field' => FieldName::Location,
            'action' => 'set',
            'value' => 'Badkamer, bij de wastafel',
            'evidence_id' => 'c1',
        ]]);
        self::assertSame('Badkamer, bij de wastafel', $document->field(FieldName::Location)->value);
        self::assertSame(FieldState::NeedsReview, $document->field(FieldName::Element)->state);
        self::assertSame(FieldState::NeedsReview, $document->field(FieldName::Defect)->state);
        self::assertSame('sinds gisteren', $document->answers[0]['value']);
    }

    public function testModelHypothesisDoesNotBecomeReportedCause(): void
    {
        $document = IntakeDocument::initial(null);
        $document->applyProposalUpdates([[
            'field' => 'cause',
            'state' => 'reported',
            'value' => 'versleten pakking',
            'source' => 'model',
            'evidence_ids' => ['m1'],
        ]]);
        self::assertSame(FieldState::Missing, $document->field(FieldName::Cause)->state);
        self::assertNotSame([], $document->hypotheses);
    }
}
