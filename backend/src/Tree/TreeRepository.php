<?php

declare(strict_types=1);

namespace App\Tree;

final class TreeRepository
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $cache = [];

    public function __construct(private readonly string $treeDirectory)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function getPublished(string $version): array
    {
        foreach ($this->allPublished() as $tree) {
            if (($tree['version'] ?? null) === $version) {
                return $tree;
            }
        }

        throw new \RuntimeException('Unknown published tree version '.$version);
    }

    /**
     * @return array<string, mixed>
     */
    public function getActivePublished(): array
    {
        $published = $this->allPublished();
        foreach ($published as $tree) {
            if (($tree['active'] ?? false) === true) {
                return $tree;
            }
        }
        if ($published === []) {
            throw new \RuntimeException('No published decision tree found.');
        }

        return $published[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allPublished(): array
    {
        $trees = [];
        foreach (glob($this->treeDirectory.'/*.json') ?: [] as $file) {
            $tree = $this->loadFile($file);
            if (($tree['status'] ?? '') === 'published') {
                $trees[] = $tree;
            }
        }

        return $trees;
    }

    /**
     * @return array<string, mixed>
     */
    public function loadFile(string $path): array
    {
        if (isset($this->cache[$path])) {
            return $this->cache[$path];
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read '.$path);
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Tree is not an object: '.$path);
        }

        return $this->cache[$path] = $decoded;
    }
}
