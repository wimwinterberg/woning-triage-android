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
        $needles = [];
        foreach (['location', 'element', 'defect'] as $field) {
            $value = $document->fields[$field]->value;
            if (is_string($value) && $value !== '') {
                $needles[] = $this->normalizer->normalize($value);
            }
        }
        if ($needles === []) {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('n')
            ->from(ClassificationNode::class, 'n')
            ->andWhere('n.treeVersion = :version')
            ->andWhere('n.level = :level')
            ->setParameter('version', $intake->getTreeVersion())
            ->setParameter('level', 'defect')
            ->setMaxResults($limit);

        $expr = $qb->expr()->orX();
        foreach ($needles as $i => $needle) {
            $expr->add('n.normalizedLabel LIKE :n'.$i);
            $qb->setParameter('n'.$i, '%'.$needle.'%');
        }
        $qb->andWhere($expr);

        /** @var list<ClassificationNode> $nodes */
        $nodes = $qb->getQuery()->getResult();

        return array_map(static fn (ClassificationNode $node): array => $node->toMetadata(), $nodes);
    }
}
