<?php

declare(strict_types=1);

namespace App\Analyzer;

use App\Domain\FieldName;
use App\Entity\Intake;
use App\Exception\ValidationFailedException;

final class ProposalValidator
{
    public function validate(Intake $intake, AnalysisProposal $proposal): void
    {
        if ($proposal->baseRevision !== $intake->getRevision()) {
            return;
        }
        foreach ($proposal->fieldUpdates as $update) {
            if (!in_array($update['field'], FieldName::values(), true)) {
                throw new ValidationFailedException('Voorstel bevat een onbekend veld.');
            }
            $allowed = ['missing', 'reported', 'unknown', 'needs_review'];
            if (!in_array($update['state'], $allowed, true)) {
                throw new ValidationFailedException('Voorstel bevat een ongeldige veldstatus.');
            }
            if (($update['state'] ?? '') === 'reported' && ($update['field'] ?? '') === 'cause' && ($update['source'] ?? '') !== 'user_message') {
                throw new ValidationFailedException('Een modelvermoeden mag geen gemelde oorzaak worden.');
            }
            foreach ($update['evidence_ids'] ?? [] as $evidenceId) {
                if (!is_string($evidenceId) || $evidenceId === '') {
                    throw new ValidationFailedException('Voorstel bevat een ongeldige bewijsverwijzing.');
                }
            }
        }
    }
}
