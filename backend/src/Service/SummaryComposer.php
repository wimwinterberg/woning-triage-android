<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Intake;

final class SummaryComposer
{
    /**
     * @return array{resident_text: string, work_description_nl: string}
     */
    public function compose(Intake $intake): array
    {
        $document = $intake->document();
        $location = $document->field(\App\Domain\FieldName::Location)->value ?? 'Onbekende locatie';
        $element = $document->field(\App\Domain\FieldName::Element)->value ?? 'Onbekend element';
        $defect = $document->field(\App\Domain\FieldName::Defect)->value ?? 'Onbekend defect';
        $causeField = $document->field(\App\Domain\FieldName::Cause);
        $causeNl = $causeField->state === \App\Domain\FieldState::Unknown
            ? 'Oorzaak onbekend.'
            : 'Gemelde oorzaak: '.($causeField->value ?? 'onbekend').'. Niet technisch vastgesteld.';

        $work = sprintf('%s: %s %s. %s', $location, $element, $defect, $causeNl);

        $language = $intake->getConversationLanguage();
        if (str_starts_with($language, 'en')) {
            $causeEn = $causeField->state === \App\Domain\FieldState::Unknown
                ? 'The cause is unknown.'
                : 'Reported cause: '.($causeField->value ?? 'unknown').'. Not technically confirmed.';
            $resident = sprintf('The problem is in the %s: the %s %s. %s', $location, $element, $defect, $causeEn);
        } else {
            $resident = $work;
        }

        return [
            'resident_text' => $resident,
            'work_description_nl' => $work,
        ];
    }
}
