<?php

declare(strict_types=1);

namespace App\Tree;

use App\Domain\FieldName;
use App\Domain\FieldState;
use App\Domain\IntakeDocument;

final class ConditionEvaluator
{
    /**
     * @param array<string, mixed> $when
     */
    public function matches(array $when, IntakeDocument $document): bool
    {
        if (isset($when['always']) && $when['always'] === true) {
            return true;
        }
        if (isset($when['exists'])) {
            return $this->exists((string) $when['exists'], $document);
        }
        if (isset($when['equals']) && is_array($when['equals'])) {
            return $this->equals($when['equals'], $document);
        }
        if (isset($when['in']) && is_array($when['in'])) {
            $field = (string) $when['in']['field'];
            $haystack = $when['in']['values'] ?? [];

            return in_array($this->read($field, $document), $haystack, true);
        }
        if (isset($when['all']) && is_array($when['all'])) {
            foreach ($when['all'] as $child) {
                if (!$this->matches($child, $document)) {
                    return false;
                }
            }

            return true;
        }
        if (isset($when['any']) && is_array($when['any'])) {
            foreach ($when['any'] as $child) {
                if ($this->matches($child, $document)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    private function exists(string $field, IntakeDocument $document): bool
    {
        if ($field === 'address.verified') {
            return $document->isAddressVerified();
        }
        $name = FieldName::tryFrom($field);
        if ($name === null) {
            return false;
        }

        return $document->field($name)->state->isPresent();
    }

    /**
     * @param array<string, mixed> $equals
     */
    private function equals(array $equals, IntakeDocument $document): bool
    {
        $field = (string) ($equals['field'] ?? '');
        $expected = $equals['value'] ?? null;

        return $this->read($field, $document) === $expected;
    }

    private function read(string $path, IntakeDocument $document): mixed
    {
        if (str_ends_with($path, '.state')) {
            $name = FieldName::tryFrom(substr($path, 0, -6));
            if ($name === null) {
                return null;
            }

            return $document->field($name)->state->value;
        }
        if ($path === 'address.verification_status') {
            return $document->addressVerificationStatus();
        }
        $name = FieldName::tryFrom($path);
        if ($name === null) {
            return null;
        }
        $field = $document->field($name);

        return $field->state === FieldState::Reported ? $field->value : $field->state->value;
    }
}
