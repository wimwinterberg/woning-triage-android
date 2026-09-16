<?php

declare(strict_types=1);

namespace App\Classification;

use App\Entity\ClassificationNode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Imports Wim's classification catalog. Source IDs are kept as-is; uniqueness is tree_version + path.
 * planning_duration is stored as unused source metadata and never exposed in API/report/prompts.
 */
final class ClassificationImporter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LabelNormalizer $normalizer,
    ) {
    }

    /**
     * @return array{version: string, nodes: int, source_hash: string, source_bytes: int}
     */
    public function import(string $path, string $version): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Classification file not found: '.$path);
        }
        $bytes = (int) filesize($path);
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read classification file.');
        }
        $hash = hash('sha256', $raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $roots = $decoded['data']['ledoData'] ?? null;
        if (!is_array($roots)) {
            throw new \RuntimeException('Unexpected classification root; expected status/data/ledoData.');
        }

        $this->entityManager->createQuery('DELETE FROM App\\Entity\\ClassificationNode n WHERE n.treeVersion = :v')
            ->setParameter('v', $version)
            ->execute();

        $count = 0;
        foreach ($roots as $gebouw) {
            $count += $this->walk($version, $gebouw, 'gebouwtype', null, []);
        }
        $this->entityManager->flush();

        return [
            'version' => $version,
            'nodes' => $count,
            'source_hash' => $hash,
            'source_bytes' => $bytes,
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $pathParts
     */
    private function walk(string $version, array $node, string $level, ?string $parentPath, array $pathParts): int
    {
        $label = (string) ($node['label'] ?? '');
        $value = (string) ($node['value'] ?? '');
        $pathParts[] = $level.':'.$value;
        $pathKey = implode('/', $pathParts);
        $competence = isset($node['rm_competence_id']) ? (string) $node['rm_competence_id'] : null;
        $duration = isset($node['planning_duration']) ? (float) $node['planning_duration'] : null;

        $entity = new ClassificationNode(
            $version,
            $level,
            $value,
            $label,
            $this->normalizer->normalize($label),
            $pathKey,
            $parentPath,
            $competence,
            $duration,
        );
        $this->entityManager->persist($entity);
        $count = 1;

        $children = $node['children'] ?? [];
        if (is_array($children) && $children !== []) {
            $childLevel = match ($level) {
                'gebouwtype' => 'location',
                'location' => 'element',
                'element' => 'defect',
                default => 'other',
            };
            foreach ($children as $child) {
                if (is_array($child)) {
                    $count += $this->walk($version, $child, $childLevel, $pathKey, $pathParts);
                }
            }
        }

        if ($count % 200 === 0) {
            $this->entityManager->flush();
            $this->entityManager->clear();
        }

        return $count;
    }
}
