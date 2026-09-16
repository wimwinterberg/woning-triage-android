<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Doctrine\OpenEntityManager;
use App\Entity\Intake;
use App\Service\AuthService;
use App\Service\IntakeEventPublisher;
use App\Service\IntakeService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IntakeEventPublisherTest extends KernelTestCase
{
    public function testSkipsAStolenSequenceInsteadOfFailing(): void
    {
        self::bootKernel();
        $auth = static::getContainer()->get(AuthService::class);
        $user = $auth->createUser('event-publisher')['user'];
        $intake = static::getContainer()->get(IntakeService::class)->create($user, 'text');
        $publisher = static::getContainer()->get(IntakeEventPublisher::class);

        $first = $publisher->publish($intake, 'intake.updated');
        $connection = static::getContainer()->get('doctrine')->getManager()->getConnection();
        $stolen = $first->getSequence() + 1;
        $connection->insert('intake_event', [
            'id' => 'evt_stolen_publisher_'.$stolen,
            'sequence' => $stolen,
            'type' => 'intake.updated',
            'revision' => $intake->getRevision(),
            'payload' => [],
            'created_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'intake_id' => $intake->getId(),
        ], [
            'payload' => 'json',
            'created_at' => 'datetime_immutable',
        ]);

        $next = $publisher->publish($intake, 'intake.updated', ['source' => 'retry']);
        self::assertSame($stolen + 1, $next->getSequence());

        $events = $publisher->after($intake, $first->getSequence() - 1);
        $sequences = array_map(static fn ($event) => $event->getSequence(), $events);
        self::assertContains($first->getSequence(), $sequences);
        self::assertContains($stolen, $sequences);
        self::assertContains($stolen + 1, $sequences);
    }

    public function testPublishesAfterTheEntityManagerWasClosed(): void
    {
        self::bootKernel();
        $auth = static::getContainer()->get(AuthService::class);
        $user = $auth->createUser('event-publisher-closed')['user'];
        $intakeId = static::getContainer()->get(IntakeService::class)->create($user, 'text')->getId();

        $closed = static::getContainer()->get('doctrine')->getManager();
        $closed->close();
        self::assertFalse($closed->isOpen());

        $open = static::getContainer()->get(OpenEntityManager::class)->get();
        $intake = $open->find(Intake::class, $intakeId);
        self::assertInstanceOf(Intake::class, $intake);

        $event = static::getContainer()->get(IntakeEventPublisher::class)->publish($intake, 'intake.updated');
        self::assertGreaterThan(0, $event->getSequence());
        self::assertTrue($open->isOpen());
    }
}
