<?php

declare(strict_types=1);

namespace App\Tree;

use App\Domain\IntakeDocument;
use App\Domain\RiskState;

final class DecisionTreeEngine
{
    public function __construct(private readonly ConditionEvaluator $evaluator = new ConditionEvaluator())
    {
    }

    /**
     * @param array<string, mixed> $tree
     * @return array{id: string, kind: string, target: ?string, text: string, outcome: ?string, review_required: bool}
     */
    public function next(array $tree, IntakeDocument $document, string $language): array
    {
        if ($document->riskState()->blocksNormalCompletion()) {
            $node = $this->findTerminal($tree, 'human_review') ?? $this->node($tree, (string) $tree['start']);

            return $this->present($node, $language, true);
        }

        if ($document->clarificationCount >= (int) $tree['max_clarifications']) {
            $node = $this->findTerminal($tree, 'human_review') ?? $this->node($tree, (string) $tree['start']);

            return $this->present($node, $language, true);
        }

        if ($this->isPristine($document)) {
            return $this->present($this->node($tree, (string) $tree['start']), $language, false);
        }

        $currentId = (string) $tree['start'];
        $guard = 0;
        while ($guard++ < 50) {
            $node = $this->node($tree, $currentId);
            if (($node['kind'] ?? '') === 'terminal') {
                return $this->present($node, $language, ($node['outcome'] ?? '') === 'human_review');
            }
            $matched = null;
            foreach ($node['transitions'] ?? [] as $transition) {
                if ($this->evaluator->matches($transition['when'] ?? [], $document)) {
                    $matched = $transition['to'];
                    break;
                }
            }
            if ($matched === null) {
                return $this->present($node, $language, false);
            }
            $target = $this->node($tree, (string) $matched);
            if (($target['kind'] ?? '') === 'question') {
                return $this->present($target, $language, false);
            }
            $currentId = (string) $matched;
        }

        $review = $this->findTerminal($tree, 'human_review');

        return $this->present($review ?? $this->node($tree, (string) $tree['start']), $language, true);
    }

    private function isPristine(IntakeDocument $document): bool
    {
        foreach ($document->fields as $field) {
            if ($field->state !== \App\Domain\FieldState::Missing) {
                return false;
            }
        }

        return $document->address === null && $document->answers === [];
    }

    /**
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    private function node(array $tree, string $id): array
    {
        $node = $tree['nodes'][$id] ?? null;
        if (!is_array($node)) {
            throw new \RuntimeException('Unknown tree node '.$id);
        }
        $node['id'] = $id;

        return $node;
    }

    /**
     * @param array<string, mixed> $tree
     * @return array<string, mixed>|null
     */
    private function findTerminal(array $tree, string $outcome): ?array
    {
        foreach ($tree['nodes'] as $id => $node) {
            if (($node['kind'] ?? null) === 'terminal' && ($node['outcome'] ?? null) === $outcome) {
                $node['id'] = $id;

                return $node;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @return array{id: string, kind: string, target: ?string, text: string, outcome: ?string, review_required: bool}
     */
    private function present(array $node, string $language, bool $reviewRequired): array
    {
        $texts = $node['texts'] ?? [];
        $text = $texts[$language] ?? $texts['nl-NL'] ?? $node['semantic'] ?? $node['text'] ?? '';

        return [
            'id' => (string) $node['id'],
            'kind' => (string) ($node['kind'] ?? 'question'),
            'target' => $node['target'] ?? null,
            'text' => (string) $text,
            'outcome' => $node['outcome'] ?? null,
            'review_required' => $reviewRequired || (($node['outcome'] ?? null) === 'human_review'),
        ];
    }
}
