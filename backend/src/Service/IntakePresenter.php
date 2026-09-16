<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AnalysisTask;
use App\Entity\Intake;
use App\Entity\IntakeMessage;
use Doctrine\ORM\EntityManagerInterface;

final class IntakePresenter
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Intake $intake): array
    {
        $document = $intake->document();
        $fields = [];
        foreach ($document->fields as $name => $field) {
            $fields[$name] = $field->toArray();
        }

        $active = $this->entityManager->createQuery(
            'SELECT t FROM App\\Entity\\AnalysisTask t WHERE t.intake = :i AND t.status IN (:s) ORDER BY t.createdAt ASC'
        )
            ->setParameter('i', $intake)
            ->setParameter('s', [AnalysisTask::PENDING, AnalysisTask::RUNNING])
            ->getResult();

        $address = $document->address;
        if (is_array($address)) {
            unset($address['provider'], $address['latitude'], $address['longitude']);
            if (isset($address['candidates']) && is_array($address['candidates'])) {
                $safe = [];
                foreach ($address['candidates'] as $candidate) {
                    if (!is_array($candidate)) {
                        continue;
                    }
                    unset($candidate['provider_id']);
                    $safe[] = $candidate;
                }
                $address['candidates'] = $safe;
            }
        }

        $payload = [
            'id' => $intake->getId(),
            'revision' => $intake->getRevision(),
            'status' => $intake->getStatus()->value,
            'tree_version' => $intake->getTreeVersion(),
            'demo' => $intake->isDemo(),
            'conversation_language' => $intake->getConversationLanguage(),
            'language_mode' => $intake->getLanguageMode()->value,
            'fields' => $fields,
            'answers' => $document->answers,
            'hypotheses' => $document->hypotheses,
            'risk' => $document->risk,
            'next_question' => $document->nextQuestion,
            'summary' => $document->summary,
            'address' => $address,
            'report_id' => $intake->getReportId(),
            'active_tasks' => array_map(static fn (AnalysisTask $task): array => $task->toArray(), $active),
            'created_at' => $intake->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            'updated_at' => $intake->getUpdatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            'confirmed_at' => $intake->getConfirmedAt()?->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
        ];

        $this->assertNoPlanningDuration($payload);

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function messages(Intake $intake): array
    {
        /** @var list<IntakeMessage> $messages */
        $messages = $this->entityManager->createQuery('SELECT m FROM App\\Entity\\IntakeMessage m WHERE m.intake = :i ORDER BY m.sequence ASC')
            ->setParameter('i', $intake)
            ->getResult();

        return array_map(static fn (IntakeMessage $message): array => $message->toArray(), $messages);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertNoPlanningDuration(array $payload): void
    {
        $encoded = json_encode($payload) ?: '';
        if (str_contains($encoded, 'planning_duration')) {
            throw new \LogicException('planning_duration leaked into an API payload');
        }
    }
}
