<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\OpenEntityManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OpenEntityManagerTest extends KernelTestCase
{
    public function testReopensAClosedEntityManager(): void
    {
        self::bootKernel();
        $closed = static::getContainer()->get('doctrine')->getManager();
        $closed->close();
        self::assertFalse($closed->isOpen());

        $reopened = static::getContainer()->get(OpenEntityManager::class)->get();
        self::assertTrue($reopened->isOpen());
    }
}
