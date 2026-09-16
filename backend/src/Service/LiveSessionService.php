<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\IdGenerator;
use App\Entity\Intake;
use App\Entity\VoiceSession;
use App\Exception\ValidationFailedException;
use App\Live\ConversationPrompt;
use App\Live\GptLiveClient;
use App\Live\LiveGatewayCommandQueue;
use Doctrine\ORM\EntityManagerInterface;

final class LiveSessionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GptLiveClient $gptLiveClient,
        private readonly IntakeEventPublisher $events,
        private readonly LiveGatewayCommandQueue $gatewayQueue,
    ) {
    }

    public function start(Intake $intake, string $sdpOffer): VoiceSession
    {
        if (trim($sdpOffer) === '') {
            throw new ValidationFailedException('sdp_offer ontbreekt.');
        }
        $this->closeOpenSessions($intake, 'replaced');
        $instructions = ConversationPrompt::text(false, $intake->getConversationLanguage());
        $result = $this->gptLiveClient->createWebRtcSession($sdpOffer, $instructions);
        $expires = new \DateTimeImmutable('+15 minutes', new \DateTimeZone('UTC'));
        $session = new VoiceSession(IdGenerator::prefixed('voice'), $intake, $result->sdpAnswer, $expires, $result->providerSessionId);
        $this->entityManager->persist($session);
        $this->entityManager->flush();
        $session->markActive();
        $this->events->publish($intake, 'voice_session.updated', ['voice_session_id' => $session->getId(), 'status' => $session->getStatus()]);
        $this->entityManager->flush();
        if (!$result->fake) {
            $this->gatewayQueue->enqueue($session->getId());
        }

        return $session;
    }

    public function getOwned(Intake $intake, string $sessionId): VoiceSession
    {
        $session = $this->entityManager->find(VoiceSession::class, $sessionId);
        if (!$session instanceof VoiceSession || $session->getIntake()->getId() !== $intake->getId()) {
            throw new \App\Exception\NotFoundException();
        }

        return $session;
    }

    public function stop(VoiceSession $session): VoiceSession
    {
        if (!$session->isOpen() && $session->getStatus() !== VoiceSession::CLOSING) {
            return $session;
        }
        $session->requestStop();
        $providerId = $session->getProviderSessionId();
        $finalized = $providerId !== null ? $this->gptLiveClient->closeSession($providerId) : false;
        $session->close('user_stop', $finalized);
        $this->events->publish($session->getIntake(), 'voice_session.updated', [
            'voice_session_id' => $session->getId(),
            'status' => $session->getStatus(),
        ]);
        $this->entityManager->flush();

        return $session;
    }

    public function closeOpenSessions(Intake $intake, string $reason): void
    {
        /** @var list<VoiceSession> $sessions */
        $sessions = $this->entityManager->createQuery(
            'SELECT v FROM App\\Entity\\VoiceSession v WHERE v.intake = :i AND v.status IN (:s)'
        )
            ->setParameter('i', $intake)
            ->setParameter('s', [VoiceSession::CONNECTING, VoiceSession::ACTIVE, VoiceSession::CLOSING])
            ->getResult();
        foreach ($sessions as $session) {
            if ($session->getProviderSessionId()) {
                $this->gptLiveClient->closeSession($session->getProviderSessionId());
            }
            $session->close($reason, false);
            $this->events->publish($intake, 'voice_session.updated', [
                'voice_session_id' => $session->getId(),
                'status' => $session->getStatus(),
            ]);
        }
    }
}
