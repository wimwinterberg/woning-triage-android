<?php

declare(strict_types=1);

namespace App\Tests\Analyzer;

use App\Analyzer\HeuristicIntakeAnalyzer;
use App\Domain\IntakeDocument;
use App\Entity\Intake;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class HeuristicIntakeAnalyzerTest extends TestCase
{
    public function testKitchenTapFillsThreeLedoFieldsWithoutInventingCause(): void
    {
        $intake = $this->intake();
        $proposal = (new HeuristicIntakeAnalyzer())->analyze($intake, 'De keukenkraan druppelt sinds gisteren.', 'msg1', 0);
        $fields = [];
        foreach ($proposal->fieldUpdates as $update) {
            $fields[$update['field']] = $update;
        }
        self::assertSame('Keuken', $fields['location']['value']);
        self::assertSame('Kraan', $fields['element']['value']);
        self::assertStringContainsString('Druppelt', (string) $fields['defect']['value']);
        self::assertArrayNotHasKey('cause', $fields);
        self::assertSame('sinds gisteren', $proposal->independentTime);
        self::assertNull($proposal->addressHint);
    }

    public function testUnknownCauseWhenAskingCause(): void
    {
        $proposal = (new HeuristicIntakeAnalyzer())->analyze(
            $this->intake('cause'),
            'Ik weet het niet',
            'msg2',
            0,
        );
        self::assertTrue($proposal->causeUnknown);
        self::assertSame('unknown', $proposal->fieldUpdates[0]['state']);
        self::assertSame('cause', $proposal->fieldUpdates[0]['field']);
    }

    public function testOnbekendIsUnknownCause(): void
    {
        $proposal = (new HeuristicIntakeAnalyzer())->analyze($this->intake('cause'), 'onbekend', 'msg3', 0);
        self::assertTrue($proposal->causeUnknown);
        self::assertSame('unknown', $proposal->fieldUpdates[0]['state']);
    }

    public function testReportedCauseWhenAskingCause(): void
    {
        $proposal = (new HeuristicIntakeAnalyzer())->analyze(
            $this->intake('cause'),
            'De pakking is versleten',
            'msg4',
            0,
        );
        self::assertFalse($proposal->causeUnknown);
        self::assertSame('cause', $proposal->fieldUpdates[0]['field']);
        self::assertSame('reported', $proposal->fieldUpdates[0]['state']);
        self::assertSame('De pakking is versleten', $proposal->fieldUpdates[0]['value']);
    }

    public function testAskedLocationCapturesNonDictionaryRoom(): void
    {
        $proposal = (new HeuristicIntakeAnalyzer())->analyze($this->intake('location'), 'boven bij de trap', 'msg5', 0);
        self::assertSame('location', $proposal->fieldUpdates[0]['field']);
        self::assertSame('boven bij de trap', $proposal->fieldUpdates[0]['value']);
    }

    public function testParsesSpelledDutchPostcodeWithoutHouseNumber(): void
    {
        $proposal = (new HeuristicIntakeAnalyzer())->analyze(
            $this->intake('address'),
            '3573 Simon Johan',
            'msg6',
            0,
        );
        self::assertSame('3573 SJ', $proposal->addressHint['postcode']);
        self::assertNull($proposal->addressHint['house_number']);
        self::assertSame([], $proposal->fieldUpdates);
    }

    public function testParsesSpacedPostcodeWithHouseNumber(): void
    {
        $proposal = (new HeuristicIntakeAnalyzer())->analyze(
            $this->intake('address'),
            '3573 SJ 12',
            'msg7',
            0,
        );
        self::assertSame('3573 SJ', $proposal->addressHint['postcode']);
        self::assertSame(12, $proposal->addressHint['house_number']);
    }

    public function testParsesSpokenPostcodeWords(): void
    {
        $proposal = (new HeuristicIntakeAnalyzer())->analyze(
            $this->intake('address'),
            'Vijf dertig drieënzeventig Simon Johan',
            'msg8',
            0,
        );
        self::assertSame('3573 SJ', $proposal->addressHint['postcode']);
        self::assertNull($proposal->addressHint['house_number']);
    }

    public function testParsesZeventigSttAs3573(): void
    {
        $proposal = (new HeuristicIntakeAnalyzer())->analyze(
            $this->intake('address'),
            'Drie vijf zeventig Simon Johan',
            'msg9',
            0,
        );
        self::assertSame('3573 SJ', $proposal->addressHint['postcode']);
    }

    public function testParsesGluedHundredsAndSingleAddressClaim(): void
    {
        $proposal = (new HeuristicIntakeAnalyzer())->analyze(
            $this->intake('address'),
            'Er is maar één adres. Oldeburgstraat tweehonderdzeven',
            'msg10',
            0,
        );
        self::assertSame(207, $proposal->addressHint['house_number']);
        self::assertSame('Oldeburgstraat', $proposal->addressHint['street']);
        self::assertTrue($proposal->addressHint['unique_claim']);
    }

    private function intake(?string $target = null): Intake
    {
        $user = new User('user_test', 'tester');
        $next = $target === null ? null : [
            'id' => 'ask_'.$target,
            'target' => $target,
            'text' => 'vraag',
        ];

        return new Intake('intake_test', $user, 'demo-ledo-1', 'conversation-v3', IntakeDocument::initial($next));
    }
}
