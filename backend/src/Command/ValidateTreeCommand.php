<?php

declare(strict_types=1);

namespace App\Command;

use App\Tree\TreePublicationValidator;
use App\Tree\TreeRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'woningtriage:validate-tree', description: 'Validate published decision trees')]
final class ValidateTreeCommand extends Command
{
    public function __construct(
        private readonly TreeRepository $trees,
        private readonly TreePublicationValidator $validator,
        private readonly string $treeDirectory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ok = true;
        foreach (glob($this->treeDirectory.'/*.json') ?: [] as $file) {
            $tree = $this->trees->loadFile($file);
            $errors = $this->validator->validate($tree);
            $output->writeln(basename($file).': '.(($tree['status'] ?? '?')).' version='.($tree['version'] ?? '?'));
            if ($errors !== []) {
                $ok = false;
                foreach ($errors as $error) {
                    $output->writeln('  - '.$error);
                }
            } else {
                $output->writeln('  valid');
            }
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
