<?php

declare(strict_types=1);

namespace App\Service;

use App\Address\AddressProvider;
use App\Analyzer\IntakeAnalyzer;
use App\Analyzer\ProposalValidator;
use App\Classification\ClassificationSearchService;
use App\Domain\AddressNormalizer;
use App\Domain\FieldName;
use App\Domain\IdGenerator;
use App\Domain\IntakeStatus;
use App\Domain\LanguageMode;
use App\Entity\AnalysisTask;
use App\Entity\Intake;
use App\Entity\IntakeMessage;
use App\Entity\Report;
use App\Entity\User;
use App\Exception\AddressLookupStaleException;
use App\Exception\AddressNotVerifiedException;
use App\Exception\IntakeBusyException;
use App\Exception\IntakeIncompleteException;
use App\Exception\IntakeLockedException;
use App\Exception\NotFoundException;
use App\Exception\SummaryStaleException;
use App\Exception\ValidationFailedException;
use App\Tree\DecisionTreeEngine;
use App\Tree\TreeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class IntakeService
{
    private const MAX_MESSAGE = 4000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TreeRepository $treeRepository,
        private readonly DecisionTreeEngine $treeEngine,
        private readonly IntakeAnalyzer $analyzer,
        private readonly ProposalValidator $proposalValidator,
        private readonly IntakeEventPublisher $events,
        private readonly IntakePresenter $presenter,
        private readonly AddressProvider $addressProvider,
        private readonly AddressNormalizer $addressNormalizer,
        private readonly SummaryComposer $summaryComposer,
        private readonly ClassificationSearchService $classificationSearch,
        private readonly string $promptVersion,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function create(User $user, string $inputMode): Intake
    {
        if (!in_array($inputMode, ['voice', 'text'], true)) {
            throw new ValidationFailedException('input_mode moet voice of text zijn.');
        }
        $tree = $this->treeRepository->getActivePublished();
        $opening = $this->treeEngine->next($tree, \App\Domain\IntakeDocument::initial(null), 'nl-NL');
        $document = \App\Domain\IntakeDocument::initial([
            'id' => $opening['id'],
            'target' => $opening['target'],
            'text' => $opening['text'],
        ]);
        $intake = new Intake(IdGenerator::prefixed('intake'), $user, (string) $tree['version'], $this->promptVersion, $document);
        $this->entityManager->persist($intake);
        $this->entityManager->flush();
        $this->events->publish($intake, 'intake.updated');
        $this->entityManager->flush();

        return $intake;
    }

    public function getOwned(string $id, User $user): Intake
    {
        $intake = $this->entityManager->find(Intake::class, $id);
        if (!$intake instanceof Intake || !$intake->belongsTo($user)) {
            throw new NotFoundException();
        }

        return $intake;
    }

    /**
     * @return array<string, mixed>
     */
    public function addMessage(Intake $intake, int $expectedRevision, string $clientMessageId, string $text): array
    {
        $this->assertMutable($intake);
        $intake->assertExpectedRevision($expectedRevision);
        $clientMessageId = trim($clientMessageId);
        if ($clientMessageId === '' || strlen($clientMessageId) > 64) {
            throw new ValidationFailedException('client_message_id ontbreekt of is ongeldig.');
        }
        $text = trim($text);
        $this->assertMessageText($text);
        $this->assertUniqueClientMessage($intake, $clientMessageId, $text);
        if ($this->hasOpenAnalysis($intake)) {
            throw new IntakeBusyException();
        }

        $sequence = $this->nextMessageSequence($intake);
        $message = new IntakeMessage($clientMessageId, $intake, $sequence, 'resident', $intake->getConversationLanguage(), $text, 'typed');
        $this->entityManager->persist($message);

        $task = new AnalysisTask(IdGenerator::prefixed('task'), $intake, 'analyze', $intake->getRevision());
        $this->entityManager->persist($task);
        $this->entityManager->flush();
        $this->events->publish($intake, 'task.updated', ['task_id' => $task->getId(), 'status' => $task->getStatus()]);
        $this->entityManager->flush();

        $this->runAnalysis($intake, $task, $text, $clientMessageId);

        return $task->toArray();
    }

    public function runAnalysis(Intake $intake, AnalysisTask $task, string $text, string $messageId): void
    {
        $task->markRunning();
        $this->entityManager->flush();
        try {
            $proposal = $this->analyzer->analyze($intake, $text, $messageId, $task->getBaseRevision());
            $this->proposalValidator->validate($intake, $proposal);

            $this->entityManager->refresh($intake);
            if ($intake->getStatus()->isLocked()) {
                $task->supersede();
                $this->entityManager->flush();

                return;
            }
            if ($intake->getRevision() !== $task->getBaseRevision()) {
                $task->supersede();
                $this->events->publish($intake, 'task.updated', ['task_id' => $task->getId(), 'status' => $task->getStatus()]);
                $this->entityManager->flush();

                return;
            }

            $document = $intake->document();
            $this->maybeConfirmPending($intake, $document, $text, $messageId, $proposal->explicitConfirmationAttempt);
            $document->applyProposalUpdates($proposal->fieldUpdates);
            foreach ($proposal->hypotheses as $hypothesis) {
                $document->hypotheses[] = array_merge(['id' => IdGenerator::prefixed('hyp')], $hypothesis);
            }
            if ($proposal->independentTime !== null) {
                $document->addIndependentAnswer('observed_since', $proposal->independentTime, $messageId);
            }
            if ($proposal->suggestedLanguage !== null) {
                $intake->setConversationLanguage($proposal->suggestedLanguage);
                $document->invalidateSummary();
            }

            if ($proposal->addressHint !== null) {
                $this->applyAddressHint($intake, $document, $proposal->addressHint);
            }

            $this->refreshNextQuestion($intake, $document);
            $intake->replaceDocument($document);
            $intake->bumpRevision();
            $task->succeed($intake->getRevision());
            $this->maybeAddAssistantQuestion($intake, $document);
            $this->events->publish($intake, 'intake.updated');
            $this->events->publish($intake, 'task.updated', ['task_id' => $task->getId(), 'status' => $task->getStatus()]);
            $this->entityManager->flush();
        } catch (ValidationFailedException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->entityManager->isOpen()) {
                $task->fail('analysis_failed', 'Het antwoord kon niet worden verwerkt.');
                $this->events->publish($intake, 'task.updated', ['task_id' => $task->getId(), 'status' => $task->getStatus()]);
                $this->entityManager->flush();
            }
            throw $exception;
        }
    }

    /**
     * @param list<array{field: string, action: string, value?: string}> $changes
     */
    public function correctFields(Intake $intake, int $expectedRevision, array $changes): Intake
    {
        $this->assertMutable($intake);
        $intake->assertExpectedRevision($expectedRevision);
        if ($changes === []) {
            throw new ValidationFailedException('Er is geen wijziging opgegeven.');
        }
        $normalized = [];
        foreach ($changes as $change) {
            $field = FieldName::tryFrom((string) ($change['field'] ?? ''));
            $action = (string) ($change['action'] ?? '');
            if ($field === null || !in_array($action, ['set', 'mark_unknown', 'clear'], true)) {
                throw new ValidationFailedException('Ongeldige veldcorrectie.');
            }
            $value = isset($change['value']) ? trim((string) $change['value']) : '';
            if ($action === 'set' && ($value === '' || mb_strlen($value) > 500)) {
                throw new ValidationFailedException('Veldwaarde ontbreekt of is te lang.');
            }
            if ($action !== 'set' && $value !== '') {
                throw new ValidationFailedException('Deze actie verwacht geen waarde.');
            }
            $normalized[] = [
                'field' => $field,
                'action' => $action,
                'value' => $value,
                'evidence_id' => IdGenerator::prefixed('corr'),
            ];
        }

        $this->supersedeOpenTasks($intake);
        $document = $intake->document();
        $document->applyUserCorrections($normalized);
        $this->refreshNextQuestion($intake, $document);
        $intake->replaceDocument($document);
        $intake->setStatus($document->riskState()->blocksNormalCompletion() ? IntakeStatus::ReviewRequired : IntakeStatus::Collecting);
        $intake->bumpRevision();
        $this->events->publish($intake, 'intake.updated');
        $this->entityManager->flush();

        return $intake;
    }

    public function changeLanguage(Intake $intake, int $expectedRevision, string $mode, ?string $language): Intake
    {
        $this->assertMutable($intake);
        $intake->assertExpectedRevision($expectedRevision);
        $languageMode = LanguageMode::tryFrom($mode) ?? throw new ValidationFailedException('Ongeldige taalmodus.');
        if ($languageMode === LanguageMode::Manual) {
            if ($language === null || !preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $language)) {
                throw new ValidationFailedException('Ongeldige taaltag.');
            }
            $intake->setConversationLanguage($language);
        }
        $intake->setLanguageMode($languageMode);
        $document = $intake->document();
        $document->invalidateSummary();
        $this->refreshNextQuestion($intake, $document);
        $intake->replaceDocument($document);
        $intake->setStatus(IntakeStatus::Collecting);
        $intake->bumpRevision();
        $this->events->publish($intake, 'intake.updated');
        $this->entityManager->flush();

        return $intake;
    }

    /**
     * @return array<string, mixed>
     */
    public function lookupAddress(Intake $intake, int $expectedRevision, string $postcode, int|string $houseNumber, ?string $addition): array
    {
        $this->assertMutable($intake);
        $intake->assertExpectedRevision($expectedRevision);
        try {
            $normalizedPostcode = $this->addressNormalizer->normalizePostcode($postcode);
            $normalizedNumber = $this->addressNormalizer->normalizeHouseNumber($houseNumber);
            $normalizedAddition = $this->addressNormalizer->normalizeAddition($addition);
        } catch (\InvalidArgumentException $exception) {
            throw new ValidationFailedException($exception->getMessage());
        }

        $candidates = array_map(
            static fn ($candidate): array => $candidate->toArray(),
            $this->addressProvider->lookup($normalizedPostcode, $normalizedNumber, $normalizedAddition),
        );
        $lookupId = IdGenerator::prefixed('lookup');
        $document = $intake->document();
        $document->recordAddressInput([
            'postcode' => $normalizedPostcode,
            'house_number' => $normalizedNumber,
            'addition' => $normalizedAddition,
            'lookup_id' => $lookupId,
            'candidates' => $candidates,
            'lookup_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'provider' => 'configured',
        ]);
        $this->prepareAddressFollowUp($document, $intake->getConversationLanguage());
        $intake->replaceDocument($document);
        $intake->setStatus(IntakeStatus::Collecting);
        $intake->bumpRevision();
        $this->events->publish($intake, 'intake.updated');
        $this->entityManager->flush();

        return [
            'lookup_id' => $lookupId,
            'revision' => $intake->getRevision(),
            'address_revision' => $document->address['address_revision'] ?? 0,
            'candidates' => $candidates,
        ];
    }

    public function verifyAddress(
        Intake $intake,
        int $expectedRevision,
        string $lookupId,
        string $candidateId,
        int $addressRevision,
        string $channel,
        ?string $evidenceMessageId,
    ): Intake {
        $this->assertMutable($intake);
        $intake->assertExpectedRevision($expectedRevision);
        if (!in_array($channel, ['voice', 'ui'], true)) {
            throw new ValidationFailedException('Ongeldig bevestigingskanaal.');
        }
        if ($channel === 'voice' && ($evidenceMessageId === null || $evidenceMessageId === '')) {
            throw new ValidationFailedException('Gesproken adresbevestiging vereist een bewijstbericht.');
        }
        if ($channel === 'ui') {
            $evidenceMessageId = null;
        }
        $document = $intake->document();
        try {
            $document->verifyAddress($lookupId, $candidateId, $addressRevision, $channel, $evidenceMessageId);
        } catch (\RuntimeException) {
            throw new AddressLookupStaleException();
        }
        $document->pendingAddressQuestionId = null;
        $this->refreshNextQuestion($intake, $document);
        $intake->replaceDocument($document);
        $intake->bumpRevision();
        $this->events->publish($intake, 'intake.updated');
        $this->entityManager->flush();

        return $intake;
    }

    /**
     * @return array<string, mixed>
     */
    public function requestSummary(Intake $intake, int $expectedRevision): array
    {
        $this->assertMutable($intake);
        $intake->assertExpectedRevision($expectedRevision);
        if ($this->hasOpenAnalysis($intake)) {
            throw new IntakeBusyException();
        }
        $document = $intake->document();
        if ($document->riskState()->blocksNormalCompletion()) {
            throw new ValidationFailedException('Deze intake vereist menselijke beoordeling.');
        }
        $missing = $document->incompleteFieldIds();
        if ($missing !== []) {
            throw new IntakeIncompleteException($missing);
        }
        if (!$document->isAddressVerified()) {
            throw new AddressNotVerifiedException();
        }
        $tree = $this->treeRepository->getPublished($intake->getTreeVersion());
        $next = $this->treeEngine->next($tree, $document, $intake->getConversationLanguage());
        if (($next['outcome'] ?? null) !== 'summary_possible') {
            throw new IntakeIncompleteException(['tree']);
        }

        $task = new AnalysisTask(IdGenerator::prefixed('task'), $intake, 'summary', $intake->getRevision());
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        $texts = $this->summaryComposer->compose($intake);
        $summaryId = IdGenerator::prefixed('summary');
        $document = $intake->document();
        $intake->bumpRevision();
        $document->setSummary([
            'id' => $summaryId,
            'source_revision' => $intake->getRevision(),
            'language' => $intake->getConversationLanguage(),
            'resident_text' => $texts['resident_text'],
            'work_description_nl' => $texts['work_description_nl'],
        ]);
        $matches = $this->classificationSearch->suggest($intake);
        if ($matches !== []) {
            $document->classification = [
                'tree_version' => $intake->getTreeVersion(),
                'candidates' => $matches,
            ];
        }
        $intake->replaceDocument($document);
        $intake->setStatus(IntakeStatus::ReadyForConfirmation);
        $task->succeed($intake->getRevision());
        $this->addAssistantMessage($intake, $texts['resident_text']);
        $this->events->publish($intake, 'intake.updated');
        $this->events->publish($intake, 'task.updated', ['task_id' => $task->getId(), 'status' => $task->getStatus()]);
        $this->entityManager->flush();

        return $task->toArray();
    }

    public function confirm(
        Intake $intake,
        int $expectedRevision,
        string $summaryId,
        string $channel = 'ui',
        ?string $evidenceMessageId = null,
    ): Intake {
        $this->assertMutable($intake);
        if ($this->hasOpenAnalysis($intake)) {
            throw new IntakeBusyException();
        }
        $existing = $this->entityManager->getRepository(Report::class)->findOneBy(['intake' => $intake]);
        if ($existing instanceof Report) {
            return $intake;
        }

        $intake->assertExpectedRevision($expectedRevision);
        $document = $intake->document();
        $summary = $document->summary;
        if ($summary === null || ($summary['id'] ?? null) !== $summaryId) {
            throw new SummaryStaleException();
        }
        if (in_array($summaryId, $document->invalidatedSummaryIds, true)) {
            throw new SummaryStaleException();
        }
        if ((int) ($summary['source_revision'] ?? -1) !== $intake->getRevision()) {
            throw new SummaryStaleException();
        }
        if (!$document->isAddressVerified()) {
            throw new AddressNotVerifiedException();
        }
        if ($channel === 'voice') {
            if ($evidenceMessageId === null) {
                throw new ValidationFailedException('Gesproken afronding vereist bewijs.');
            }
            if ($document->pendingSummaryQuestionId !== $summaryId) {
                throw new ValidationFailedException('Deze bevestiging hoort niet bij de actuele samenvatting.');
            }
        }

        $sourceRevision = $intake->getRevision();
        $reportId = IdGenerator::prefixed('report');
        $payload = $this->buildReportPayload($intake, $document, $summaryId, $channel, $evidenceMessageId, $sourceRevision, $reportId);
        $report = new Report($reportId, $intake, $sourceRevision, $payload);
        $this->entityManager->persist($report);
        $intake->confirm($reportId);
        $this->events->publish($intake, 'intake.updated');
        try {
            $this->entityManager->flush();
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            $this->entityManager->clear();
            $reloaded = $this->entityManager->find(Intake::class, $intake->getId());
            if ($reloaded instanceof Intake && $reloaded->getReportId() !== null) {
                return $reloaded;
            }
            throw new IntakeLockedException();
        }

        return $intake;
    }

    public function cancel(Intake $intake, int $expectedRevision): Intake
    {
        if ($intake->getStatus() === IntakeStatus::Confirmed) {
            throw new IntakeLockedException();
        }
        $intake->assertExpectedRevision($expectedRevision);
        $this->supersedeOpenTasks($intake);
        $intake->setStatus(IntakeStatus::Cancelled);
        $intake->bumpRevision();
        $this->events->publish($intake, 'intake.updated');
        $this->entityManager->flush();

        return $intake;
    }

    public function getTask(Intake $intake, string $taskId): AnalysisTask
    {
        $task = $this->entityManager->find(AnalysisTask::class, $taskId);
        if (!$task instanceof AnalysisTask || $task->getIntake()->getId() !== $intake->getId()) {
            throw new NotFoundException();
        }

        return $task;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Intake $intake): array
    {
        return $this->presenter->present($intake);
    }

    private function assertMutable(Intake $intake): void
    {
        if ($intake->getStatus()->isLocked()) {
            throw new IntakeLockedException();
        }
    }

    private function assertMessageText(string $text): void
    {
        if ($text === '') {
            throw new ValidationFailedException('Bericht mag niet leeg zijn.');
        }
        if (grapheme_strlen($text) > self::MAX_MESSAGE) {
            throw new ValidationFailedException('Bericht is te lang.');
        }
    }

    private function assertUniqueClientMessage(Intake $intake, string $clientMessageId, string $text): void
    {
        $existing = $this->entityManager->find(IntakeMessage::class, $clientMessageId);
        if ($existing instanceof IntakeMessage) {
            if ($existing->toArray()['text'] !== $text) {
                throw new \App\Exception\IdempotencyConflictException();
            }
        }
    }

    private function hasOpenAnalysis(Intake $intake): bool
    {
        $count = (int) $this->entityManager->createQuery(
            'SELECT COUNT(t.id) FROM App\\Entity\\AnalysisTask t WHERE t.intake = :i AND t.status IN (:s)'
        )
            ->setParameter('i', $intake)
            ->setParameter('s', [AnalysisTask::PENDING, AnalysisTask::RUNNING])
            ->getSingleScalarResult();

        return $count > 0;
    }

    private function supersedeOpenTasks(Intake $intake): void
    {
        /** @var list<AnalysisTask> $tasks */
        $tasks = $this->entityManager->createQuery(
            'SELECT t FROM App\\Entity\\AnalysisTask t WHERE t.intake = :i AND t.status IN (:s)'
        )
            ->setParameter('i', $intake)
            ->setParameter('s', [AnalysisTask::PENDING, AnalysisTask::RUNNING])
            ->getResult();
        foreach ($tasks as $task) {
            $task->supersede();
            $this->events->publish($intake, 'task.updated', ['task_id' => $task->getId(), 'status' => $task->getStatus()]);
        }
    }

    private function nextMessageSequence(Intake $intake): int
    {
        $max = (int) $this->entityManager->createQuery('SELECT MAX(m.sequence) FROM App\\Entity\\IntakeMessage m WHERE m.intake = :i')
            ->setParameter('i', $intake)
            ->getSingleScalarResult();

        return $max + 1;
    }

    private function refreshNextQuestion(Intake $intake, \App\Domain\IntakeDocument $document): void
    {
        $tree = $this->treeRepository->getPublished($intake->getTreeVersion());
        $next = $this->treeEngine->next($tree, $document, $intake->getConversationLanguage());
        if ($next['review_required'] || ($next['outcome'] ?? null) === 'human_review') {
            $intake->setStatus(IntakeStatus::ReviewRequired);
            $document->nextQuestion = [
                'id' => $next['id'],
                'target' => $next['target'],
                'text' => $next['text'],
            ];

            return;
        }
        if (($next['outcome'] ?? null) === 'summary_possible' && $document->isAddressVerified() && !$document->hasBlockingNeedsReview()) {
            $document->nextQuestion = [
                'id' => $next['id'],
                'target' => null,
                'text' => $next['text'],
            ];

            return;
        }
        $document->nextQuestion = [
            'id' => $next['id'],
            'target' => $next['target'],
            'text' => $next['text'],
        ];
        $this->prepareAddressFollowUp($document, $intake->getConversationLanguage());
    }

    private function prepareAddressFollowUp(\App\Domain\IntakeDocument $document, string $language): void
    {
        $address = $document->address;
        if (!is_array($address) || ($address['verification_status'] ?? '') === 'verified') {
            return;
        }
        $nl = str_starts_with($language, 'nl');
        $postcode = is_string($address['postcode'] ?? null) ? trim((string) $address['postcode']) : '';
        $houseNumber = $address['house_number'] ?? null;
        $hasNumber = is_int($houseNumber) ? $houseNumber > 0 : (is_numeric($houseNumber) && (int) $houseNumber > 0);
        $candidates = $address['candidates'] ?? [];
        $lookupId = $address['lookup_id'] ?? null;
        $lookedUp = is_string($lookupId) && $lookupId !== '';

        if ($postcode !== '' && !$hasNumber) {
            $document->nextQuestion = [
                'id' => 'address_ask_house_number',
                'target' => 'address',
                'text' => $nl
                    ? 'Wat is het huisnummer van de woning?'
                    : 'What is the house number of the home?',
            ];

            return;
        }
        if ($postcode === '' && $hasNumber) {
            $document->nextQuestion = [
                'id' => 'address_ask_postcode',
                'target' => 'address',
                'text' => $nl
                    ? 'Wat is de postcode? Vier cijfers en twee letters; letters mag u spellen, zoals Simon Johan voor SJ.'
                    : 'What is the postcode? Four digits and two letters; you may spell the letters, for example Simon Johan for SJ.',
            ];

            return;
        }
        if ($postcode === '' || !$hasNumber) {
            return;
        }
        if (!$lookedUp) {
            $document->nextQuestion = [
                'id' => 'address_lookup_unavailable',
                'target' => 'address',
                'text' => $nl
                    ? 'Het adres kon even niet worden opgezocht. Zeg de postcode en het huisnummer nog eens.'
                    : 'The address could not be looked up just now. Please say the postcode and house number again.',
            ];

            return;
        }
        if ($candidates === []) {
            $document->nextQuestion = [
                'id' => 'address_no_match',
                'target' => 'address',
                'text' => $nl
                    ? 'Er is geen adres gevonden. Controleer postcode, huisnummer en eventuele toevoeging.'
                    : 'No address was found. Please check the postcode, house number and any addition.',
            ];

            return;
        }
        if (count($candidates) === 1) {
            $display = $candidates[0]['display_address'] ?? '';
            $questionId = 'address_confirm_'.$candidates[0]['candidate_id'];
            $document->pendingAddressQuestionId = $questionId;
            $document->nextQuestion = [
                'id' => $questionId,
                'target' => 'address',
                'text' => $nl
                    ? 'Is dit uw adres: '.$display.'?'
                    : 'Is this your address: '.$display.'?',
            ];

            return;
        }
        $document->pendingAddressQuestionId = null;
        $document->nextQuestion = [
            'id' => 'address_select',
            'target' => 'address',
            'text' => $nl
                ? 'Er zijn meerdere adressen gevonden. Kies de juiste toevoeging.'
                : 'Several addresses were found. Please choose the correct addition.',
        ];
    }

    /**
     * @param array{postcode: ?string, house_number: ?int, addition: ?string} $hint
     */
    private function applyAddressHint(Intake $intake, \App\Domain\IntakeDocument $document, array $hint): void
    {
        $existing = is_array($document->address) ? $document->address : [];
        if (($existing['verification_status'] ?? '') === 'verified') {
            return;
        }

        $postcode = $this->mergePostcode($hint['postcode'] ?? null, $existing['postcode'] ?? null);
        $incomingNumber = $hint['house_number'] ?? null;
        $number = null;
        if (is_int($incomingNumber) && $incomingNumber > 0) {
            try {
                $number = $this->addressNormalizer->normalizeHouseNumber($incomingNumber);
            } catch (\InvalidArgumentException) {
                $number = null;
            }
        } elseif ($postcode !== null && $postcode === ($existing['postcode'] ?? null)) {
            $existingNumber = $existing['house_number'] ?? null;
            $number = is_int($existingNumber) ? $existingNumber : (is_numeric($existingNumber) ? (int) $existingNumber : null);
            if ($number !== null && $number < 1) {
                $number = null;
            }
        }

        $incomingAddition = $hint['addition'] ?? null;
        $addition = null;
        try {
            if (is_string($incomingAddition) && trim($incomingAddition) !== '') {
                $addition = $this->addressNormalizer->normalizeAddition($incomingAddition);
            } elseif ($postcode === ($existing['postcode'] ?? null) && $number === ($existing['house_number'] ?? null)) {
                $addition = $this->addressNormalizer->normalizeAddition(
                    is_string($existing['addition'] ?? null) ? (string) $existing['addition'] : null,
                );
            }
        } catch (\InvalidArgumentException) {
            $addition = null;
        }

        if ($postcode === null && $number === null) {
            return;
        }

        if ($postcode === null || $number === null) {
            $document->recordAddressInput([
                'postcode' => $postcode,
                'house_number' => $number,
                'addition' => $addition,
                'lookup_id' => null,
                'candidates' => [],
                'lookup_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
                'provider' => 'configured',
            ]);

            return;
        }

        try {
            $candidates = array_map(
                static fn ($candidate): array => $candidate->toArray(),
                $this->addressProvider->lookup($postcode, $number, $addition),
            );
        } catch (\App\Exception\AddressLookupUnavailableException) {
            $this->logger->info('Address lookup unavailable', [
                'intake_id' => $intake->getId(),
                'candidate_count' => null,
            ]);
            $document->recordAddressInput([
                'postcode' => $postcode,
                'house_number' => $number,
                'addition' => $addition,
                'lookup_id' => null,
                'candidates' => [],
                'lookup_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
                'provider' => 'configured',
            ]);

            return;
        }

        $this->logger->info('Address lookup finished', [
            'intake_id' => $intake->getId(),
            'candidate_count' => count($candidates),
        ]);
        $document->recordAddressInput([
            'postcode' => $postcode,
            'house_number' => $number,
            'addition' => $addition,
            'lookup_id' => IdGenerator::prefixed('lookup'),
            'candidates' => $candidates,
            'lookup_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'provider' => 'configured',
        ]);
    }

    private function mergePostcode(?string $incoming, mixed $existing): ?string
    {
        if (is_string($incoming) && trim($incoming) !== '') {
            try {
                return $this->addressNormalizer->normalizePostcode($incoming);
            } catch (\InvalidArgumentException) {
                return is_string($existing) && $existing !== '' ? $existing : null;
            }
        }

        return is_string($existing) && $existing !== '' ? $existing : null;
    }

    private function maybeConfirmPending(
        Intake $intake,
        \App\Domain\IntakeDocument $document,
        string $text,
        string $messageId,
        bool $bareYes,
    ): void {
        $nextId = $document->nextQuestion['id'] ?? null;
        $normalized = mb_strtolower(trim($text));
        $explicitYes = (bool) preg_match('/^(ja|yes)([,.!]|\s+dat (klopt|is het)|, that is (correct|it))?\.?$/u', $normalized)
            || (bool) preg_match('/dat klopt|that(?:\'s| is) (correct|my address)|dat is mijn adres/u', $normalized);

        if ($document->pendingAddressQuestionId !== null && $nextId === $document->pendingAddressQuestionId && ($explicitYes || $bareYes)) {
            $candidate = $document->address['candidates'][0] ?? null;
            if (is_array($candidate) && count($document->address['candidates'] ?? []) === 1) {
                $document->verifyAddress(
                    (string) $document->address['lookup_id'],
                    (string) $candidate['candidate_id'],
                    (int) $document->address['address_revision'],
                    'voice',
                    $messageId,
                );
                $document->pendingAddressQuestionId = null;
            }

            return;
        }

        if ($intake->getStatus() === IntakeStatus::ReadyForConfirmation && $document->summary !== null && ($explicitYes) && $nextId === ($document->summary['id'] ?? null)) {
            $document->pendingSummaryQuestionId = $document->summary['id'];
        }
    }

    private function maybeAddAssistantQuestion(Intake $intake, \App\Domain\IntakeDocument $document): void
    {
        $text = $document->nextQuestion['text'] ?? null;
        if (is_string($text) && $text !== '') {
            $this->addAssistantMessage($intake, $text);
        }
    }

    private function addAssistantMessage(Intake $intake, string $text): void
    {
        $message = new IntakeMessage(
            IdGenerator::prefixed('message'),
            $intake,
            $this->nextMessageSequence($intake),
            'assistant',
            $intake->getConversationLanguage(),
            $text,
            'backend',
        );
        $this->entityManager->persist($message);
        $this->events->publish($intake, 'assistant.message', [
            'message_id' => $message->getId(),
            'text' => $text,
            'language' => $intake->getConversationLanguage(),
        ]);
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function buildReportPayload(
        Intake $intake,
        \App\Domain\IntakeDocument $document,
        string $summaryId,
        string $channel,
        ?string $evidenceMessageId,
        int $sourceRevision,
        string $reportId,
    ): array {
        $summary = $document->summary ?? [];
        $address = $document->address ?? [];
        unset($address['candidates'], $address['provider']);
        $payload = [
            'report_id' => $reportId,
            'intake_id' => $intake->getId(),
            'source_revision' => $sourceRevision,
            'tree_version' => $intake->getTreeVersion(),
            'work_description_nl' => $summary['work_description_nl'] ?? '',
            'resident_summary' => $summary['resident_text'] ?? '',
            'summary_id' => $summaryId,
            'fields' => array_map(static fn ($field) => $field->toArray(), $document->fields),
            'hypotheses' => $document->hypotheses,
            'answers' => $document->answers,
            'classification' => $document->classification,
            'address' => $address,
            'messages' => $this->presenter->messages($intake),
            'confirmation' => [
                'channel' => $channel,
                'evidence_message_id' => $evidenceMessageId,
                'summary_id' => $summaryId,
                'confirmed_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            ],
        ];
        $encoded = json_encode($payload) ?: '';
        if (str_contains($encoded, 'planning_duration')) {
            throw new \LogicException('planning_duration must not appear in reports');
        }

        return $payload;
    }
}
