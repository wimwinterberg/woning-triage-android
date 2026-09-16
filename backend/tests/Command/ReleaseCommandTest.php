<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ReleaseCommandTest extends KernelTestCase
{
    public function testReleaseMigratesImportsAndValidatesTrees(): void
    {
        $application = new Application(self::bootKernel());
        $tester = new CommandTester($application->find('woningtriage:release'));
        $status = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertStringContainsString('Imported classification', $tester->getDisplay());
    }
}
