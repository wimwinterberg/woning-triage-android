<?php

declare(strict_types=1);

namespace App\Analyzer;

use App\Entity\Intake;

interface IntakeAnalyzer
{
    public function analyze(Intake $intake, string $text, string $messageId, int $baseRevision): AnalysisProposal;
}
