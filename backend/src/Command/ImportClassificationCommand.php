<?php

declare(strict_types=1);

namespace App\Command;

use App\Classification\ClassificationImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'woningtriage:import-classification', description: 'Import a LEDO classification catalog JSON')]
final class ImportClassificationCommand extends Command
{
    public function __construct(private readonly ClassificationImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to beslisboom-prod.json or a fixture')
            ->addOption('version', null, InputOption::VALUE_REQUIRED, 'Tree version tag to store', 'demo-ledo-1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getOption('file');
        $version = (string) $input->getOption('version');
        $result = $this->importer->import($file, $version);
        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $expected = '4ba8f60d9b2072d05ffa03e7796b0be7fefd2b4a7f9a23d3a0565f99af6d901c';
        if ($result['source_bytes'] === 53115659 && $result['source_hash'] === $expected) {
            $output->writeln('Production catalog hash matched.');
        } else {
            $output->writeln('Note: this import is not the production catalog (53,115,659 bytes / SHA-256 '.$expected.').');
        }

        return Command::SUCCESS;
    }
}
