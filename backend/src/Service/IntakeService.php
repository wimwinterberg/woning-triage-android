<?php

declare(strict_types=1);

namespace App\Service;

use App\Address\AddressLookupLogger;
use App\Address\AddressProvider;
use App\Analyzer\IntakeAnalyzer;
use App\Analyzer\ProposalValidator;
use App\Classification\ClassificationSearchService;
use App\Domain\AddressNormalizer;
use App\Domain\DutchPostcodeParser;
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
        private readonly AddressLookupLogger $addressLookupLogger = new AddressLookupLogger(),
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
            $spokenYes = $this->isSpokenYes($text, $proposal->explicitConfirmationAttempt);
            $wasOnSummary = $this->isOnSpokenSummaryQuestion($document);
            if ($this->shouldConfirmSpokenSummary($intake, $document, $spokenYes)) {
                $this->confirmSpokenReport($intake, $document, $task, $messageId);

                return;
            }

            $this->maybeConfirmPendingAddress($document, $text, $messageId, $spokenYes);
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

            $hint = $proposal->addressHint;
            if ($hint === null && DutchPostcodeParser::claimsSingleAddress($text)) {
                $hint = [
                    'postcode' => null,
                    'house_number' => null,
                    'addition' => null,
                    'street' => null,
                    'unique_claim' => true,
                ];
            }
            if ($this->shouldRestartAddress($document, $text, $hint)) {
                $this->restartUnverifiedAddress($intake, $document);
            }
            if ($hint !== null) {
                $this->applyAddressHint($intake, $document, $hint);
            }

            $this->refreshNextQuestion($intake, $document);
            $intake->replaceDocument($document);
            $intake->bumpRevision();
            if ($this->shouldOfferSpokenSummary($intake, $document)) {
                $this->writeSummaryDocument($intake, $document);
                $this->presentSummaryQuestion($intake, $document);
                $intake->replaceDocument($document);
            } elseif ($document->summary !== null && $intake->getStatus() === IntakeStatus::ReadyForConfirmation) {
                $this->presentSummaryQuestion($intake, $document);
                $intake->replaceDocument($document);
            }
            if ($spokenYes && $wasOnSummary && $document->summary !== null && !$intake->getStatus()->isLocked()) {
                $this->confirmSpokenReport($intake, $document, $task, $messageId);

                return;
            }
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
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function lookupAddress(Intake $intake, int $expectedRevision, array $request): array
    {
        $this->assertMutable($intake);
        $intake->assertExpectedRevision($expectedRevision);
        $gps = $this->isGpsLookup($request);
        $nearbyHintCount = 0;

        try {
            if ($gps) {
                $this->addressNormalizer->assertInTheNetherlands(
                    $this->addressNormalizer->normalizeLatitude($request['latitude'] ?? null),
                    $this->addressNormalizer->normalizeLongitude($request['longitude'] ?? null),
                );
                $hints = $this->nearbyHints($request['nearby'] ?? []);
                $nearbyHintCount = count($hints);
                $candidates = $this->lookupNearbyCandidates($hints);
                $normalizedPostcode = null;
                $normalizedNumber = null;
                $normalizedAddition = null;
                $source = 'gps';
            } else {
                $normalizedPostcode = $this->addressNormalizer->normalizePostcode((string) ($request['postcode'] ?? ''));
                $normalizedNumber = $this->addressNormalizer->normalizeHouseNumber($request['house_number'] ?? 0);
                $normalizedAddition = $this->addressNormalizer->normalizeAddition(
                    array_key_exists('addition', $request) && $request['addition'] !== null
                        ? (string) $request['addition']
                        : null,
                );
                $candidates = $this->lookupCandidates($normalizedPostcode, $normalizedNumber, $normalizedAddition);
                $source = 'postcode';
            }
        } catch (\InvalidArgumentException $exception) {
            throw new ValidationFailedException($exception->getMessage());
        }

        $lookupId = IdGenerator::prefixed('lookup');
        $this->addressLookupLogger->log('intake_stored', [
            'intake_id' => $intake->getId(),
            'source' => $gps ? 'gps' : 'ui',
            'provider' => $this->addressProvider::class,
            'candidate_count' => count($candidates),
            'has_addition_filter' => $normalizedAddition !== null,
            'nearby_hint_count' => $nearbyHintCount,
        ]);
        $document = $intake->document();
        $document->recordAddressInput([
            'postcode' => $normalizedPostcode,
            'house_number' => $normalizedNumber,
            'addition' => $normalizedAddition,
            'lookup_id' => $lookupId,
            'candidates' => $candidates,
            'lookup_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'provider' => 'configured',
            'source' => $source,
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

        $document = $intake->document();
        $intake->bumpRevision();
        $texts = $this->writeSummaryDocument($intake, $document);
        $this->presentSummaryQuestion($intake, $document);
        $intake->replaceDocument($document);
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
        $candidates = $this->candidateList($address['candidates'] ?? []);
        $lookupId = $address['lookup_id'] ?? null;
        $lookedUp = is_string($lookupId) && $lookupId !== '';
        $gps = ($address['source'] ?? '') === 'gps';

        if ($lookedUp && $candidates !== []) {
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
                $this->addressLookupLogger->log('follow_up', [
                    'question_id' => 'address_confirm',
                    'candidate_count' => 1,
                    'source' => $gps ? 'gps' : 'postcode',
                ]);

                return;
            }
            $document->pendingAddressQuestionId = null;
            $sameHouse = $this->candidatesShareHouse($candidates);
            $document->nextQuestion = [
                'id' => 'address_select',
                'target' => 'address',
                'text' => $this->addressSelectPrompt($nl, $gps, $sameHouse),
            ];
            $this->addressLookupLogger->log('follow_up', [
                'question_id' => 'address_select',
                'candidate_count' => count($candidates),
                'source' => $gps ? 'gps' : 'postcode',
            ]);

            return;
        }
        if ($lookedUp && $candidates === []) {
            $document->nextQuestion = [
                'id' => 'address_no_match',
                'target' => 'address',
                'text' => $gps
                    ? ($nl
                        ? 'Er is geen adres gevonden bij uw locatie. Controleer de lijst of vul postcode en huisnummer in.'
                        : 'No address was found for your location. Check the list or enter the postcode and house number.')
                    : ($nl
                        ? 'Er is geen adres gevonden. Controleer postcode, huisnummer en eventuele toevoeging.'
                        : 'No address was found. Please check the postcode, house number and any addition.'),
            ];
            $this->addressLookupLogger->log('follow_up', [
                'question_id' => 'address_no_match',
                'candidate_count' => 0,
                'source' => $gps ? 'gps' : 'postcode',
            ]);

            return;
        }

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
                    ? 'Wat is de postcode? Vier cijfers en twee letters; letters mag u spellen, zoals Simon Johan of Sierra Juliet voor SJ.'
                    : 'What is the postcode? Four digits and two letters. You may say the digits as words, like three five seven three, and spell the letters as S J or Sierra Juliet for SJ.',
            ];

            return;
        }
        if ($postcode === '' || !$hasNumber) {
            return;
        }
        $document->nextQuestion = [
            'id' => 'address_lookup_unavailable',
            'target' => 'address',
            'text' => $nl
                ? 'Het adres kon even niet worden opgezocht. Zeg de postcode en het huisnummer nog eens.'
                : 'The address could not be looked up just now. Please say the postcode and house number again.',
        ];
    }

    /**
     * @param array{postcode: ?string, house_number: ?int, addition: ?string, street?: ?string, unique_claim?: bool} $hint
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

        $this->addressLookupLogger->log('hint', [
            'intake_id' => $intake->getId(),
            'provider' => $this->addressProvider::class,
            'has_postcode' => $postcode !== null,
            'has_house_number' => $number !== null,
            'house_number_digits' => $number !== null ? strlen((string) $number) : 0,
            'has_addition' => $addition !== null,
            'has_street' => is_string($hint['street'] ?? null) && trim((string) $hint['street']) !== '',
            'unique_claim' => (bool) ($hint['unique_claim'] ?? false),
        ]);

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
            $this->addressLookupLogger->log('unavailable', [
                'intake_id' => $intake->getId(),
                'provider' => $this->addressProvider::class,
                'candidate_count' => 0,
                'source' => 'voice',
            ]);
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

        $beforeNarrow = count($candidates);
        $candidates = $this->narrowCandidates($candidates, $postcode, $number, $hint);
        $this->addressLookupLogger->log('intake_stored', [
            'intake_id' => $intake->getId(),
            'source' => 'voice',
            'provider' => $this->addressProvider::class,
            'candidate_count' => count($candidates),
            'narrowed_from' => $beforeNarrow,
            'preferred_plain' => $beforeNarrow > 1 && count($candidates) === 1,
            'unique_claim' => (bool) ($hint['unique_claim'] ?? false),
        ]);
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

    /**
     * @param list<array<string, mixed>> $candidates
     * @param array{postcode: ?string, house_number: ?int, addition: ?string, street?: ?string, unique_claim?: bool} $hint
     * @return list<array<string, mixed>>
     */
    private function narrowCandidates(array $candidates, string $postcode, int $number, array $hint): array
    {
        $matched = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $candidateNumber = (int) ($candidate['house_number'] ?? 0);
            $candidatePostcode = (string) ($candidate['postcode'] ?? '');
            if ($candidateNumber !== $number || !AddressNormalizer::samePostcode($candidatePostcode, $postcode)) {
                continue;
            }
            $matched[] = $candidate;
        }

        $street = is_string($hint['street'] ?? null) ? trim((string) $hint['street']) : '';
        if ($street !== '' && $matched !== []) {
            $byStreet = [];
            foreach ($matched as $candidate) {
                if ($this->streetsMatch($street, (string) ($candidate['street'] ?? ''))) {
                    $byStreet[] = $candidate;
                }
            }
            if ($byStreet !== []) {
                $matched = $byStreet;
            }
        }

        $additionSpecified = is_string($hint['addition'] ?? null) && trim((string) $hint['addition']) !== '';
        if (!$additionSpecified && count($matched) > 1) {
            $plain = [];
            foreach ($matched as $candidate) {
                $addition = $candidate['addition'] ?? null;
                if ($addition === null || $addition === '') {
                    $plain[] = $candidate;
                }
            }
            if (count($plain) === 1) {
                $matched = $plain;
            } elseif ((bool) ($hint['unique_claim'] ?? false) && $plain !== []) {
                $matched = $plain;
            }
        }

        return $this->uniqueCandidates($matched);
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function uniqueCandidates(array $candidates): array
    {
        $seen = [];
        $unique = [];
        foreach ($candidates as $candidate) {
            $key = strtolower(trim((string) ($candidate['display_address'] ?? '')));
            if ($key === '') {
                $key = implode(':', [
                    AddressNormalizer::compactPostcode((string) ($candidate['postcode'] ?? '')),
                    (string) ($candidate['house_number'] ?? ''),
                    strtolower(trim((string) ($candidate['street'] ?? ''))),
                    strtolower(trim((string) ($candidate['addition'] ?? ''))),
                ]);
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function isGpsLookup(array $request): bool
    {
        return array_key_exists('latitude', $request) || array_key_exists('longitude', $request);
    }

    /**
     * @return list<array{postcode: string, house_number: int, addition: ?string}>
     */
    private function nearbyHints(mixed $nearby): array
    {
        if (!is_array($nearby)) {
            return [];
        }
        $hints = [];
        $seen = [];
        foreach ($nearby as $item) {
            if (count($hints) >= 8) {
                break;
            }
            if (!is_array($item)) {
                continue;
            }
            try {
                $postcode = $this->addressNormalizer->normalizePostcode((string) ($item['postcode'] ?? ''));
                $houseNumber = $this->addressNormalizer->normalizeHouseNumber($item['house_number'] ?? 0);
                $addition = $this->addressNormalizer->normalizeAddition(
                    array_key_exists('addition', $item) && $item['addition'] !== null
                        ? (string) $item['addition']
                        : null,
                );
            } catch (\InvalidArgumentException) {
                continue;
            }
            $key = AddressNormalizer::compactPostcode($postcode).':'.$houseNumber.':'.strtolower((string) $addition);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $hints[] = [
                'postcode' => $postcode,
                'house_number' => $houseNumber,
                'addition' => $addition,
            ];
        }

        return $hints;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lookupCandidates(string $postcode, int $houseNumber, ?string $addition): array
    {
        return array_map(
            static fn ($candidate): array => $candidate->toArray(),
            $this->addressProvider->lookup($postcode, $houseNumber, $addition),
        );
    }

    /**
     * @param list<array{postcode: string, house_number: int, addition: ?string}> $hints
     * @return list<array<string, mixed>>
     */
    private function lookupNearbyCandidates(array $hints): array
    {
        $merged = [];
        $seen = [];
        foreach ($hints as $hint) {
            foreach ($this->lookupCandidates($hint['postcode'], $hint['house_number'], $hint['addition']) as $candidate) {
                $key = strtolower(trim((string) ($candidate['provider_id'] ?? $candidate['display_address'] ?? '')));
                if ($key === '') {
                    $key = implode(':', [
                        AddressNormalizer::compactPostcode((string) ($candidate['postcode'] ?? '')),
                        (string) ($candidate['house_number'] ?? ''),
                        strtolower(trim((string) ($candidate['addition'] ?? ''))),
                    ]);
                }
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $merged[] = $candidate;
                if (count($merged) >= 12) {
                    return $merged;
                }
            }
        }

        return $merged;
    }

    /**
     * @param list<array<string, mixed>> $candidates
     */
    private function candidatesShareHouse(array $candidates): bool
    {
        $postcode = null;
        $number = null;
        foreach ($candidates as $candidate) {
            $itemPostcode = AddressNormalizer::compactPostcode((string) ($candidate['postcode'] ?? ''));
            $itemNumber = (int) ($candidate['house_number'] ?? 0);
            if ($postcode === null) {
                $postcode = $itemPostcode;
                $number = $itemNumber;
                continue;
            }
            if ($itemPostcode !== $postcode || $itemNumber !== $number) {
                return false;
            }
        }

        return $candidates !== [];
    }

    private function addressSelectPrompt(bool $nl, bool $gps, bool $sameHouse): string
    {
        if ($gps || !$sameHouse) {
            return $nl
                ? 'Er zijn meerdere adressen gevonden. Kies het juiste adres in de lijst.'
                : 'Several addresses were found. Choose the correct address from the list.';
        }

        return $nl
            ? 'Er zijn meerdere adressen gevonden. Kies de juiste toevoeging.'
            : 'Several addresses were found. Please choose the correct addition.';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function candidateList(mixed $candidates): array
    {
        if (!is_array($candidates) || $candidates === []) {
            return [];
        }
        if (array_is_list($candidates)) {
            $list = [];
            foreach ($candidates as $candidate) {
                if (is_array($candidate)) {
                    $list[] = $candidate;
                }
            }

            return $list;
        }
        if (isset($candidates['candidate_id']) || isset($candidates['display_address'])) {
            return [$candidates];
        }

        return [];
    }

    private function streetsMatch(string $spoken, string $candidate): bool
    {
        $left = $this->streetKey($spoken);
        $right = $this->streetKey($candidate);
        if ($left === '' || $right === '') {
            return false;
        }
        if ($left === $right) {
            return true;
        }
        if (str_starts_with($left, $right) || str_starts_with($right, $left)) {
            return true;
        }

        return levenshtein($left, $right) <= 3 && min(strlen($left), strlen($right)) >= 6;
    }

    private function streetKey(string $name): string
    {
        $folded = mb_strtolower($name);
        $folded = str_replace(['ë', 'é', 'è', 'ï'], ['e', 'e', 'e', 'i'], $folded);
        $folded = preg_replace('/[^a-z]/', '', $folded) ?? $folded;

        return preg_replace('/(straat|laan|weg|plein|gracht|kade|singel|hof|dreef|pad|steeg|dijk|baan)$/', '', $folded) ?? $folded;
    }

    /**
     * @param array{postcode: ?string, house_number: ?int, addition: ?string, street?: ?string, unique_claim?: bool}|null $hint
     */
    private function shouldRestartAddress(\App\Domain\IntakeDocument $document, string $text, ?array $hint): bool
    {
        if ($document->isAddressVerified() || !$this->isAddressFollowUp($document)) {
            return false;
        }
        if ($this->isAddressRejection($text)) {
            return true;
        }
        $incoming = is_string($hint['postcode'] ?? null) ? trim((string) $hint['postcode']) : '';
        $existing = is_string($document->address['postcode'] ?? null) ? trim((string) $document->address['postcode']) : '';
        if ($incoming === '' || $existing === '') {
            return false;
        }

        return !AddressNormalizer::samePostcode($incoming, $existing);
    }

    private function isAddressFollowUp(\App\Domain\IntakeDocument $document): bool
    {
        $id = (string) ($document->nextQuestion['id'] ?? '');
        if ($id === '') {
            return false;
        }

        return ($document->nextQuestion['target'] ?? null) === 'address'
            || str_starts_with($id, 'address_');
    }

    private function isAddressRejection(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return false;
        }
        if (preg_match('/^(nee|neen|no)\b/u', $normalized) === 1) {
            return true;
        }

        return preg_match(
            '/verkeerd(e)?\s+(postcode|adres|huisnummer)|niet (mijn|het) adres|dat (is|klopt) niet|klopt niet|opnieuw beginnen|andere postcode|ander adres|niet de juiste|wrong (postcode|address|house number)|not my address|that(?:\'s| is) not (right|correct|my address)|start over|different postcode|falsche (postleitzahl|adresse)|yanlis/u',
            $normalized,
        ) === 1;
    }

    private function restartUnverifiedAddress(Intake $intake, \App\Domain\IntakeDocument $document): void
    {
        $document->clearUnverifiedAddress();
        $this->addressLookupLogger->log('reset', [
            'intake_id' => $intake->getId(),
            'provider' => $this->addressProvider::class,
            'reason' => 'resident_correction',
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

    private function isSpokenYes(string $text, bool $bareYes): bool
    {
        if ($this->isAddressRejection($text)) {
            return false;
        }
        if ($bareYes) {
            return true;
        }
        $normalized = $this->normalizeSpokenConfirmation($text);
        if ($normalized === '') {
            return false;
        }

        return preg_match('/^(ja|yes|ok|okay|oke|klopt|evet|hai)\b/u', $normalized) === 1
            || preg_match('/\b(dat klopt|that(?:\'s| is) (correct|my address)|dat is mijn adres|gecontroleerd|rond\s*af|afronden|leg(?:\s+het)?\s+vast|vastleggen)\b/u', $normalized) === 1;
    }

    private function normalizeSpokenConfirmation(string $text): string
    {
        $folded = mb_strtolower(trim($text));
        $folded = str_replace(["\u{FEFF}", "\u{200B}", "\u{00A0}", "\u{202F}"], ' ', $folded);
        $folded = str_replace(['ë', 'é', 'è', 'ï'], ['e', 'e', 'e', 'i'], $folded);
        $folded = preg_replace('/^[^\p{L}\p{N}]+/u', '', $folded) ?? $folded;
        $folded = trim(preg_replace('/\s+/u', ' ', $folded) ?? $folded);

        return $folded;
    }

    private function maybeConfirmPendingAddress(
        \App\Domain\IntakeDocument $document,
        string $text,
        string $messageId,
        bool $spokenYes,
    ): void {
        if (!$spokenYes || $document->isAddressVerified()) {
            return;
        }
        $candidates = $this->candidateList($document->address['candidates'] ?? []);
        if (count($candidates) !== 1) {
            return;
        }
        $lookupId = $document->address['lookup_id'] ?? null;
        if (!is_string($lookupId) || $lookupId === '') {
            return;
        }
        $nextId = (string) ($document->nextQuestion['id'] ?? '');
        if (in_array($nextId, ['address_ask_house_number', 'address_ask_postcode', 'address_lookup_unavailable', 'address_no_match', 'address_select'], true)) {
            return;
        }
        if (!$this->isAddressFollowUp($document) && $document->pendingAddressQuestionId === null) {
            return;
        }
        $candidate = $candidates[0];
        $document->verifyAddress(
            $lookupId,
            (string) $candidate['candidate_id'],
            (int) $document->address['address_revision'],
            'voice',
            $messageId,
        );
        $document->pendingAddressQuestionId = null;
    }

    private function isOnSpokenSummaryQuestion(\App\Domain\IntakeDocument $document): bool
    {
        $nextId = $document->nextQuestion['id'] ?? null;
        $summaryId = $document->summary['id'] ?? null;

        return $nextId === 'terminal_summary'
            || ($document->nextQuestion['target'] ?? null) === 'summary'
            || (is_string($summaryId) && $summaryId !== '' && $nextId === $summaryId);
    }

    private function shouldConfirmSpokenSummary(Intake $intake, \App\Domain\IntakeDocument $document, bool $spokenYes): bool
    {
        if (!$spokenYes || $document->summary === null) {
            return false;
        }
        $summaryId = $document->summary['id'] ?? null;
        if (!is_string($summaryId) || $summaryId === '') {
            return false;
        }

        return $this->isOnSpokenSummaryQuestion($document)
            && ($intake->getStatus() === IntakeStatus::ReadyForConfirmation || $intake->getStatus() === IntakeStatus::Collecting);
    }

    private function shouldOfferSpokenSummary(Intake $intake, \App\Domain\IntakeDocument $document): bool
    {
        if ($document->summary !== null || !$document->isAddressVerified() || $document->hasBlockingNeedsReview()) {
            return false;
        }
        if ($document->riskState()->blocksNormalCompletion()) {
            return false;
        }
        if (($document->nextQuestion['id'] ?? null) === 'terminal_summary') {
            return true;
        }
        $tree = $this->treeRepository->getPublished($intake->getTreeVersion());
        $next = $this->treeEngine->next($tree, $document, $intake->getConversationLanguage());

        return ($next['outcome'] ?? null) === 'summary_possible';
    }

    private function confirmSpokenReport(
        Intake $intake,
        \App\Domain\IntakeDocument $document,
        AnalysisTask $task,
        string $messageId,
    ): void {
        $summaryId = (string) ($document->summary['id'] ?? '');
        $intake->replaceDocument($document);
        $task->succeed($intake->getRevision());
        $this->entityManager->flush();
        $this->confirm($intake, $intake->getRevision(), $summaryId, 'voice', $messageId);
        $document = $intake->document();
        $this->presentClosingQuestion($intake, $document);
        $intake->replaceDocument($document);
        $this->maybeAddAssistantQuestion($intake, $document);
        $this->events->publish($intake, 'intake.updated');
        $this->events->publish($intake, 'task.updated', ['task_id' => $task->getId(), 'status' => $task->getStatus()]);
        $this->entityManager->flush();
    }

    /**
     * @return array{resident_text: string, work_description_nl: string}
     */
    private function writeSummaryDocument(Intake $intake, \App\Domain\IntakeDocument $document): array
    {
        $texts = $this->summaryComposer->compose($intake);
        $document->setSummary([
            'id' => IdGenerator::prefixed('summary'),
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
        $intake->setStatus(IntakeStatus::ReadyForConfirmation);

        return $texts;
    }

    private function presentSummaryQuestion(Intake $intake, \App\Domain\IntakeDocument $document): void
    {
        $summary = $document->summary;
        if ($summary === null) {
            return;
        }
        $id = (string) $summary['id'];
        $resident = (string) ($summary['resident_text'] ?? '');
        $nl = str_starts_with($intake->getConversationLanguage(), 'nl');
        $document->pendingSummaryQuestionId = $id;
        $document->nextQuestion = [
            'id' => $id,
            'target' => 'summary',
            'text' => $nl ? $resident.' Klopt dit?' : $resident.' Is that correct?',
        ];
    }

    private function presentClosingQuestion(Intake $intake, \App\Domain\IntakeDocument $document): void
    {
        $nl = str_starts_with($intake->getConversationLanguage(), 'nl');
        $document->nextQuestion = [
            'id' => 'intake_confirmed',
            'target' => null,
            'text' => $nl ? 'De melding is vastgelegd.' : 'The report has been recorded.',
        ];
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
