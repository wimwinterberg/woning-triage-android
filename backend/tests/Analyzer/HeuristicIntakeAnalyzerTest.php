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
    }

    public function testUnknownCause(): void
    {
        $proposal = (new HeuristicIntakeAnalyzer())->analyze($this->intake(), 'Ik weet het niet', 'msg2', 0);
        self::assertTrue($proposal->causeUnknown);
        self::assertSame('unknown', $proposal->fieldUpdates[0]['state']);
    }

    private function intake(): Intake
    {
        $user = new User('user_test', 'tester');
        return new Intake('intake_test', $user, 'demo-ledo-1', 'conversation-v1', IntakeDocument::initial(null));
    }
}
