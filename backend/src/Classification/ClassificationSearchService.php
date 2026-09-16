<?php

declare(strict_types=1);

namespace App\Classification;

use App\Entity\ClassificationNode;
use App\Entity\Intake;
use Doctrine\ORM\EntityManagerInterface;

final class ClassificationSearchService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LabelNormalizer $normalizer,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function suggest(Intake $intake, int $limit = 5): array
    {
        $document = $intake->document();
        $ruimte = $this->needle($document, 'location');
        $element = $this->needle($document, 'element');
        $defect = $this->needle($document, 'defect');
        if ($ruimte === null && $element === null && $defect === null) {
            return [];
        }

        $version = $intake->getTreeVersion();
        $parents = $this->woningPrefixes($version);
        if ($parents === []) {
            return [];
        }

        if ($ruimte !== null) {
            $rooms = $this->findLevel($version, 'location', $parents, $ruimte);
            if ($rooms === []) {
                return [];
            }
            $parents = $this->pathKeys($rooms);
        }
        if ($element !== null) {
            $parts = $this->findLevel($version, 'element', $parents, $element);
            if ($parts === []) {
                return [];
            }
            $parents = $this->pathKeys($parts);
        }

        $leaves = $this->findLevel($version, 'defect', $parents, $defect, $limit);

        return array_map(static fn (ClassificationNode $node): array => $node->toMetadata(), $leaves);
    }

    /**
     * @return list<string>
     */
    private function woningPrefixes(string $version): array
    {
        /** @var list<ClassificationNode> $nodes */
        $nodes = $this->entityManager->createQueryBuilder()
            ->select('n')
            ->from(ClassificationNode::class, 'n')
            ->andWhere('n.treeVersion = :version')
            ->andWhere('n.level = :level')
            ->setParameter('version', $version)
            ->setParameter('level', 'gebouwtype')
            ->getQuery()
            ->getResult();

        $prefixes = [];
        foreach ($nodes as $node) {
            if (WoningScope::matchesGebouwtype($node->getNormalizedLabel())) {
                $prefixes[] = $node->getPathKey();
            }
        }

        return $prefixes;
    }

    /**
     * @param list<string> $parentPrefixes
     * @return list<ClassificationNode>
     */
    private function findLevel(string $version, string $level, array $parentPrefixes, ?string $needle, int $limit = 40): array
    {
        if ($parentPrefixes === []) {
            return [];
        }
        $qb = $this->entityManager->createQueryBuilder()
            ->select('n')
            ->from(ClassificationNode::class, 'n')
            ->andWhere('n.treeVersion = :version')
            ->andWhere('n.level = :level')
            ->setParameter('version', $version)
            ->setParameter('level', $level)
            ->setMaxResults($limit);

        $paths = $qb->expr()->orX();
        foreach ($parentPrefixes as $i => $prefix) {
            $paths->add('n.pathKey LIKE :p'.$i);
            $qb->setParameter('p'.$i, $prefix.'/%');
        }
        $qb->andWhere($paths);
        if ($needle !== null && $needle !== '') {
            $qb->andWhere('n.normalizedLabel LIKE :needle')
                ->setParameter('needle', '%'.$needle.'%');
        }

        /** @var list<ClassificationNode> $nodes */
        $nodes = $qb->getQuery()->getResult();

        return $nodes;
    }

    /**
     * @param list<ClassificationNode> $nodes
     * @return list<string>
     */
    private function pathKeys(array $nodes): array
    {
        return array_values(array_map(static fn (ClassificationNode $node): string => $node->getPathKey(), $nodes));
    }

    private function needle(\App\Domain\IntakeDocument $document, string $field): ?string
    {
        $value = $document->fields[$field]->value;
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->normalizer->normalize($value);
    }
}
