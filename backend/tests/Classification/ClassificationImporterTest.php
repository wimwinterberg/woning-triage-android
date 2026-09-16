<?php

declare(strict_types=1);

namespace App\Tests\Classification;

use App\Classification\ClassificationImporter;
use App\Classification\LabelNormalizer;
use App\Entity\ClassificationNode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ClassificationImporterTest extends KernelTestCase
{
    public function testDemoCatalogImportDoesNotExposePlanningDuration(): void
    {
        self::bootKernel();
        $importer = static::getContainer()->get(ClassificationImporter::class);
        $result = $importer->import(dirname(__DIR__, 2).'/fixtures/classification/demo-catalog.json', 'demo-ledo-1');
        self::assertSame(7, $result['nodes']);
        self::assertNotSame('4ba8f60d9b2072d05ffa03e7796b0be7fefd2b4a7f9a23d3a0565f99af6d901c', $result['source_hash']);

        $nodes = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(ClassificationNode::class)
            ->findAll();
        $metadata = array_map(static fn (ClassificationNode $node): array => $node->toMetadata(), $nodes);
        self::assertStringNotContainsString('planning_duration', json_encode($metadata) ?: '');
        self::assertSame('druppelt', (new LabelNormalizer())->normalize('Druppelt'));
    }
}
