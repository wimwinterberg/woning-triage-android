<?php

declare(strict_types=1);

namespace App\Exception;

final class RevisionConflictException extends ApiException
{
    public function __construct(int $currentRevision)
    {
        parent::__construct(
            'revision_conflict',
            'De gegevens zijn ondertussen gewijzigd. Laad de actuele intake opnieuw.',
            409,
            ['current_revision' => $currentRevision],
        );
    }
}
