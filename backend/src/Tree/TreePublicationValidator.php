<?php

declare(strict_types=1);

namespace App\Tree;

final class TreePublicationValidator
{
    /**
     * @param array<string, mixed> $tree
     * @return list<string>
     */
    public function validate(array $tree): array
    {
        $errors = [];
        foreach (['version', 'status', 'owner', 'start', 'nodes', 'max_clarifications'] as $required) {
            if (!isset($tree[$required])) {
                $errors[] = "missing:$required";
            }
        }
        if ($errors !== []) {
            return $errors;
        }
        if (!is_array($tree['nodes']) || $tree['nodes'] === []) {
            return ['nodes_empty'];
        }
        $nodes = $tree['nodes'];
        $start = (string) $tree['start'];
        if (!isset($nodes[$start])) {
            $errors[] = 'start_missing';
        }

        $hasTerminal = false;
        foreach ($nodes as $id => $node) {
            if (!is_array($node)) {
                $errors[] = "node_invalid:$id";
                continue;
            }
            $kind = $node['kind'] ?? '';
            if ($kind === 'terminal') {
                $hasTerminal = true;
                if (!isset($node['outcome'])) {
                    $errors[] = "terminal_without_outcome:$id";
                }
                continue;
            }
            if ($kind !== 'question') {
                $errors[] = "unknown_kind:$id";
                continue;
            }
            if (!isset($node['semantic'], $node['answer_types'], $node['transitions'])) {
                $errors[] = "question_incomplete:$id";
                continue;
            }
            $hasUnknown = false;
            foreach ($node['transitions'] as $i => $transition) {
                if (!is_array($transition) || !isset($transition['to'], $transition['when'])) {
                    $errors[] = "transition_invalid:$id:$i";
                    continue;
                }
                if (!isset($nodes[$transition['to']])) {
                    $errors[] = "dangling_transition:$id:".$transition['to'];
                }
                if ($this->allowsUnknown($transition['when'])) {
                    $hasUnknown = true;
                }
            }
            if (($node['unknown_required'] ?? true) === true && !$hasUnknown && !($node['unknown_allowed'] ?? false)) {
                $errors[] = "missing_unknown_path:$id";
            }
        }
        if (!$hasTerminal) {
            $errors[] = 'no_terminal_route';
        }
        if ((int) $tree['max_clarifications'] < 1) {
            $errors[] = 'unbounded_clarifications';
        }

        if ($errors === [] && isset($nodes[$start])) {
            $reachable = [];
            $this->walk($start, $nodes, $reachable, []);
            foreach (array_keys($nodes) as $id) {
                if (!isset($reachable[$id]) && ($nodes[$id]['kind'] ?? '') !== 'terminal') {
                    $errors[] = "unreachable:$id";
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $when
     */
    private function allowsUnknown(array $when): bool
    {
        $encoded = json_encode($when) ?: '';

        return str_contains($encoded, 'unknown') || str_contains($encoded, '"always":true');
    }

    /**
     * @param array<string, mixed> $nodes
     * @param array<string, true> $reachable
     * @param array<string, true> $stack
     */
    private function walk(string $id, array $nodes, array &$reachable, array $stack): void
    {
        if (isset($stack[$id])) {
            return;
        }
        $reachable[$id] = true;
        $stack[$id] = true;
        $node = $nodes[$id] ?? null;
        if (!is_array($node)) {
            return;
        }
        foreach ($node['transitions'] ?? [] as $transition) {
            if (isset($transition['to'])) {
                $this->walk((string) $transition['to'], $nodes, $reachable, $stack);
            }
        }
    }
}
