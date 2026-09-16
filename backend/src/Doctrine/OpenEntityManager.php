<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Long-running workers (live-gateway) keep one EntityManager for hours.
 * A unique-constraint failure during flush closes that manager; the next
 * address lookup then fails with "The EntityManager is closed."
 */
final class OpenEntityManager
{
    public function __construct(private readonly ManagerRegistry $doctrine)
    {
    }

    public function get(): EntityManagerInterface
    {
        $em = $this->orm($this->doctrine->getManager());
        if ($em->isOpen()) {
            return $em;
        }

        error_log('Resetting closed Doctrine EntityManager');

        return $this->orm($this->doctrine->resetManager());
    }

    private function orm(object $manager): EntityManagerInterface
    {
        if (!$manager instanceof EntityManagerInterface) {
            throw new \RuntimeException('Expected Doctrine ORM EntityManager.');
        }

        return $manager;
    }
}
