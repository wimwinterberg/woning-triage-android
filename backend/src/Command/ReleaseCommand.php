<?php

declare(strict_types=1);

namespace App\Command;

use App\Classification\ClassificationImporter;
use App\Tree\TreePublicationValidator;
use App\Tree\TreeRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Production release hook for DigitalOcean App Platform PRE_DEPLOY (and Heroku release phase).
 */
#[AsCommand(name: 'woningtriage:release', description: 'Run migrations, import demo catalog, validate trees')]
final class ReleaseCommand extends Command
{
    public function __construct(
        private readonly ClassificationImporter $importer,
        private readonly TreeRepository $trees,
        private readonly TreePublicationValidator $validator,
        private readonly string $treeDirectory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Classification JSON to import', 'fixtures/classification/demo-catalog.json')
            ->addOption('tree-version', null, InputOption::VALUE_REQUIRED, 'Tree version tag', 'demo-ledo-1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $application = $this->getApplication();
        if ($application === null) {
            throw new \RuntimeException('Console application is not available.');
        }

        $kernelEnv = $application instanceof \Symfony\Bundle\FrameworkBundle\Console\Application
            ? $application->getKernel()->getEnvironment()
            : 'prod';
        if ($kernelEnv !== 'test') {
            $application->setAutoExit(false);
            try {
                $migrate = $application->find('doctrine:migrations:migrate');
                $migrateInput = new ArrayInput([
                    '--no-interaction' => true,
                    '--allow-no-migration' => true,
                ]);
                $migrateInput->setInteractive(false);
                $migrateStatus = $migrate->run($migrateInput, $output);
            } finally {
                $application->setAutoExit(true);
            }
            if ($migrateStatus !== Command::SUCCESS) {
                return $migrateStatus;
            }
        } else {
            $output->writeln('Skipping migrations in the test environment.');
        }

        $file = (string) $input->getOption('file');
        $customPath = trim((string) ($_ENV['CLASSIFICATION_IMPORT_PATH'] ?? $_SERVER['CLASSIFICATION_IMPORT_PATH'] ?? ''));
        if ($customPath !== '') {
            $file = $customPath;
        }
        $version = (string) $input->getOption('tree-version');
        $result = $this->importer->import($file, $version);
        $output->writeln('Imported classification version='.$result['version'].' nodes='.$result['nodes']);

        $ok = true;
        foreach (glob($this->treeDirectory.'/*.json') ?: [] as $treeFile) {
            $errors = $this->validator->validate($this->trees->loadFile($treeFile));
            if ($errors !== []) {
                $ok = false;
                $output->writeln(basename($treeFile).' invalid:');
                foreach ($errors as $error) {
                    $output->writeln('  - '.$error);
                }
            }
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
