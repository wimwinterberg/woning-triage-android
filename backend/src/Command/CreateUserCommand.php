<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AuthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'woningtriage:create-user', description: 'Create a pilot user and one-time activation code')]
final class CreateUserCommand extends Command
{
    public function __construct(private readonly AuthService $authService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('label', null, InputOption::VALUE_REQUIRED, 'User label', 'pilot');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->authService->createUser((string) $input->getOption('label'));
        $output->writeln('user_id='.$result['user']->getId());
        $output->writeln('activation_code='.$result['activation_code']);
        $output->writeln('The activation code is shown once. It is not stored in plaintext.');

        return Command::SUCCESS;
    }
}
