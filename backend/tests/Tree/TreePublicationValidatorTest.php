<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Tree\TreePublicationValidator;
use App\Tree\TreeRepository;
use PHPUnit\Framework\TestCase;

final class TreePublicationValidatorTest extends TestCase
{
    public function testDemoTreeIsValid(): void
    {
        $repo = new TreeRepository(dirname(__DIR__, 2).'/config/trees');
        $tree = $repo->getActivePublished();
        $errors = (new TreePublicationValidator())->validate($tree);
        self::assertSame([], $errors);
    }

    public function testMissingNodeAndLoopAreRejected(): void
    {
        $validator = new TreePublicationValidator();
        $errors = $validator->validate([
            'version' => 'bad',
            'status' => 'published',
            'owner' => 'test',
            'start' => 'a',
            'max_clarifications' => 0,
            'nodes' => [
                'a' => [
                    'kind' => 'question',
                    'semantic' => 'x',
                    'answer_types' => ['free_text'],
                    'transitions' => [
                        ['when' => ['always' => true], 'to' => 'missing-node'],
                    ],
                ],
            ],
        ]);
        self::assertContains('dangling_transition:a:missing-node', $errors);
        self::assertContains('unbounded_clarifications', $errors);
        self::assertContains('no_terminal_route', $errors);
    }
}
