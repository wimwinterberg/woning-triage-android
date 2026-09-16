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

    public function testPartialPostcodeDoesNotCountAsVerified(): void
    {
        $document = IntakeDocument::initial(null);
        $document->recordAddressInput([
            'postcode' => '3573 SJ',
            'house_number' => null,
            'addition' => null,
            'lookup_id' => null,
            'candidates' => [],
            'lookup_at' => '2026-09-16T00:00:00+00:00',
            'provider' => 'configured',
        ]);
        self::assertSame('3573 SJ', $document->address['postcode']);
        self::assertNull($document->address['house_number']);
        self::assertFalse($document->isAddressVerified());
    }

    public function testClearUnverifiedAddressDropsLookupAndCandidates(): void
    {
        $document = IntakeDocument::initial(['id' => 'address_confirm_demo', 'target' => 'address']);
        $document->recordAddressInput([
            'postcode' => '3573 SJ',
            'house_number' => 207,
            'addition' => null,
            'lookup_id' => 'lookup_demo',
            'candidates' => [['candidate_id' => 'c1', 'display_address' => 'Oldenburgerstraat 207']],
            'lookup_at' => '2026-09-16T00:00:00+00:00',
            'provider' => 'configured',
        ]);
        $document->pendingAddressQuestionId = 'address_confirm_c1';
        $revision = (int) $document->address['address_revision'];
        $document->clearUnverifiedAddress();
        self::assertSame('missing', $document->address['verification_status']);
        self::assertNull($document->address['postcode']);
        self::assertNull($document->address['house_number']);
        self::assertSame([], $document->address['candidates']);
        self::assertNull($document->pendingAddressQuestionId);
        self::assertSame($revision + 1, $document->address['address_revision']);
    }
}
