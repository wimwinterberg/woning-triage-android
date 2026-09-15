<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'classification_node')]
#[ORM\Index(columns: ['tree_version', 'level'])]
#[ORM\Index(columns: ['tree_version', 'normalized_label'])]
#[ORM\UniqueConstraint(name: 'uniq_class_path', columns: ['tree_version', 'path_key'])]
class ClassificationNode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $treeVersion;

    #[ORM\Column(length: 16)]
    private string $level;

    #[ORM\Column(length: 64)]
    private string $sourceValue;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(length: 255)]
    private string $normalizedLabel;

    #[ORM\Column(length: 512)]
    private string $pathKey;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $parentPathKey = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $competenceId = null;

    #[ORM\Column(nullable: true)]
    private ?float $sourcePlanningDuration = null;

    public function __construct(
        string $treeVersion,
        string $level,
        string $sourceValue,
        string $label,
        string $normalizedLabel,
        string $pathKey,
        ?string $parentPathKey,
        ?string $competenceId = null,
        ?float $sourcePlanningDuration = null,
    ) {
        $this->treeVersion = $treeVersion;
        $this->level = $level;
        $this->sourceValue = $sourceValue;
        $this->label = $label;
        $this->normalizedLabel = $normalizedLabel;
        $this->pathKey = $pathKey;
        $this->parentPathKey = $parentPathKey;
        $this->competenceId = $competenceId;
        $this->sourcePlanningDuration = $sourcePlanningDuration;
    }

    /**
     * @return array<string, mixed>
     */
    public function toMetadata(): array
    {
        $data = [
            'level' => $this->level,
            'source_value' => $this->sourceValue,
            'label' => $this->label,
            'path_key' => $this->pathKey,
            'tree_version' => $this->treeVersion,
        ];
        if ($this->competenceId !== null) {
            $data['competence_id'] = $this->competenceId;
        }

        return $data;
    }
}
