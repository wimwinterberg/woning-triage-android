<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Intake;
use App\Service\AuthService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class IntakeApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $tokenA;
    private string $tokenB;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $auth = static::getContainer()->get(AuthService::class);
        $userA = $auth->createUser('resident-a');
        $userB = $auth->createUser('resident-b');
        $this->tokenA = $auth->issueToken($userA['user'])['access_token'];
        $this->tokenB = $auth->issueToken($userB['user'])['access_token'];
    }

    public function testHealthIsPublic(): void
    {
        foreach (['/health', '/api/v1/health', '/'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful();
            $body = json_decode($this->client->getResponse()->getContent() ?: '[]', true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('ok', $body['status']);
            self::assertSame('woningtriage', $body['service']);
        }
    }

    public function testKitchenTapFlowWithUnknownCauseAddressAndSingleReport(): void
    {
        $intake = $this->createIntake($this->tokenA);
        self::assertSame('collecting', $intake['status']);
        self::assertSame('nl-NL', $intake['conversation_language']);
        self::assertSame('opening', $intake['next_question']['id']);

        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'message_kitchen',
            'text' => 'De keukenkraan druppelt sinds gisteren.',
        ], 'msg-1', 202);

        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame('reported', $intake['fields']['location']['state']);
        self::assertSame('Keuken', $intake['fields']['location']['value']);
        self::assertSame('Kraan', $intake['fields']['element']['value']);
        self::assertSame('missing', $intake['fields']['cause']['state']);

        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'message_unknown',
            'text' => 'Ik weet het niet',
        ], 'msg-2', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame('unknown', $intake['fields']['cause']['state']);
        self::assertNull($intake['fields']['cause']['value']);
        self::assertSame('ask_address', $intake['next_question']['id']);

        $lookup = $this->postJson('/api/v1/intakes/'.$intake['id'].'/address-lookups', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'postcode' => '1234 AB',
            'house_number' => 12,
            'addition' => 'A',
        ], 'addr-1');
        self::assertCount(1, $lookup['candidates']);
        self::assertSame('Voorbeeldstraat', $lookup['candidates'][0]['street']);

        $intake = $this->getIntake($intake['id'], $this->tokenA);
        $intake = $this->postJson('/api/v1/intakes/'.$intake['id'].'/address-verifications', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'lookup_id' => $lookup['lookup_id'],
            'candidate_id' => $lookup['candidates'][0]['candidate_id'],
            'address_revision' => $lookup['address_revision'],
            'confirmation_channel' => 'ui',
            'evidence_message_id' => null,
        ], 'addr-v1');
        self::assertSame('verified', $intake['address']['verification_status']);

        $summaryTask = $this->postJson('/api/v1/intakes/'.$intake['id'].'/summaries', $this->tokenA, [
            'expected_revision' => $intake['revision'],
        ], 'sum-1', 202);
        self::assertSame('succeeded', $summaryTask['status']);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame('ready_for_confirmation', $intake['status']);
        self::assertStringContainsString('Oorzaak onbekend', $intake['summary']['work_description_nl']);

        $confirmed = $this->postJson('/api/v1/intakes/'.$intake['id'].'/confirmations', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'summary_id' => $intake['summary']['id'],
            'confirmation_channel' => 'ui',
        ], 'conf-1');
        self::assertSame('confirmed', $confirmed['status']);
        self::assertNotNull($confirmed['report_id']);

        $retry = $this->postJson('/api/v1/intakes/'.$intake['id'].'/confirmations', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'summary_id' => $intake['summary']['id'],
            'confirmation_channel' => 'ui',
        ], 'conf-1');
        self::assertSame($confirmed['report_id'], $retry['report_id']);

        $this->client->request('GET', '/api/v1/intakes/'.$intake['id'], server: $this->auth($this->tokenB));
        self::assertResponseStatusCodeSame(404);

        $encoded = json_encode($confirmed) ?: '';
        self::assertStringNotContainsString('planning_duration', $encoded);
    }

    public function testCorrectionInvalidatesOldSummaryAndIdempotencyConflict(): void
    {
        $intake = $this->createIntake($this->tokenA);
        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => 0,
            'client_message_id' => 'm-kitchen',
            'text' => 'De keukenkraan druppelt sinds gisteren.',
        ], 'c-msg-1', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'm-unknown',
            'text' => 'Ik weet het niet',
        ], 'c-msg-2', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        $lookup = $this->postJson('/api/v1/intakes/'.$intake['id'].'/address-lookups', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'postcode' => '1234AB',
            'house_number' => 12,
            'addition' => 'A',
        ], 'c-addr');
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        $this->postJson('/api/v1/intakes/'.$intake['id'].'/address-verifications', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'lookup_id' => $lookup['lookup_id'],
            'candidate_id' => $lookup['candidates'][0]['candidate_id'],
            'address_revision' => $lookup['address_revision'],
            'confirmation_channel' => 'ui',
        ], 'c-ver');
        $this->postJson('/api/v1/intakes/'.$intake['id'].'/summaries', $this->tokenA, [
            'expected_revision' => $this->getIntake($intake['id'], $this->tokenA)['revision'],
        ], 'c-sum', 202);
        $ready = $this->getIntake($intake['id'], $this->tokenA);
        $oldSummary = $ready['summary']['id'];

        $corrected = $this->patchJson('/api/v1/intakes/'.$intake['id'].'/fields', $this->tokenA, [
            'expected_revision' => $ready['revision'],
            'changes' => [['field' => 'location', 'action' => 'set', 'value' => 'Badkamer, bij de wastafel']],
        ], 'c-corr');
        self::assertSame('needs_review', $corrected['fields']['element']['state']);
        self::assertNull($corrected['summary']);
        self::assertSame('sinds gisteren', $corrected['answers'][0]['value']);

        $this->client->jsonRequest(
            'POST',
            '/api/v1/intakes/'.$intake['id'].'/confirmations',
            ['expected_revision' => $corrected['revision'], 'summary_id' => $oldSummary],
            $this->auth($this->tokenA) + ['HTTP_IDEMPOTENCY_KEY' => 'stale-sum'],
        );
        self::assertResponseStatusCodeSame(409);

        $this->client->jsonRequest(
            'POST',
            '/api/v1/intakes/'.$intake['id'].'/messages',
            ['expected_revision' => $corrected['revision'], 'client_message_id' => 'dup', 'text' => 'Hallo'],
            $this->auth($this->tokenA) + ['HTTP_IDEMPOTENCY_KEY' => 'same-key'],
        );
        self::assertResponseStatusCodeSame(202);
        $this->client->jsonRequest(
            'POST',
            '/api/v1/intakes/'.$intake['id'].'/messages',
            ['expected_revision' => $corrected['revision'], 'client_message_id' => 'dup', 'text' => 'Andere tekst'],
            $this->auth($this->tokenA) + ['HTTP_IDEMPOTENCY_KEY' => 'same-key'],
        );
        self::assertResponseStatusCodeSame(409);
        $error = json_decode($this->client->getResponse()->getContent() ?: '[]', true);
        self::assertSame('idempotency_conflict', $error['error']['code']);
    }

    public function testMultipleAddressMatchesAndLookupFailure(): void
    {
        $intake = $this->createIntake($this->tokenA);
        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => 0,
            'client_message_id' => 'm1',
            'text' => 'De keukenkraan druppelt sinds gisteren.',
        ], 'a-m1', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'm2',
            'text' => 'Ik weet het niet',
        ], 'a-m2', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        $multi = $this->postJson('/api/v1/intakes/'.$intake['id'].'/address-lookups', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'postcode' => '1234 AB',
            'house_number' => 12,
            'addition' => null,
        ], 'a-multi');
        self::assertGreaterThan(1, count($multi['candidates']));

        $none = $this->postJson('/api/v1/intakes/'.$intake['id'].'/address-lookups', $this->tokenA, [
            'expected_revision' => $this->getIntake($intake['id'], $this->tokenA)['revision'],
            'postcode' => '9999 ZZ',
            'house_number' => 1,
            'addition' => null,
        ], 'a-none');
        self::assertSame([], $none['candidates']);

        $this->client->jsonRequest(
            'POST',
            '/api/v1/intakes/'.$intake['id'].'/address-lookups',
            ['expected_revision' => $this->getIntake($intake['id'], $this->tokenA)['revision'], 'postcode' => '1111 AA', 'house_number' => 12],
            $this->auth($this->tokenA) + ['HTTP_IDEMPOTENCY_KEY' => 'a-fail'],
        );
        self::assertResponseStatusCodeSame(503);
    }

    public function testVoiceSessionFakeAndEnglishLanguageSwitch(): void
    {
        $intake = $this->createIntake($this->tokenA);
        $session = $this->postJson('/api/v1/intakes/'.$intake['id'].'/voice-sessions', $this->tokenA, [
            'sdp_offer' => "v=0\r\no=- 0 0 IN IP4 127.0.0.1\r\n",
        ], 'voice-1', 201);
        self::assertSame('webrtc', $session['transport']);
        self::assertNotSame('', $session['sdp_answer']);
        self::assertFalse($session['live']);
        $queue = static::getContainer()->get(\App\Live\LiveGatewayCommandQueue::class);
        self::assertNotContains($session['id'], $queue->pending());

        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => 0,
            'client_message_id' => 'en1',
            'text' => 'The kitchen tap is leaking since yesterday please because it is broken',
        ], 'en-msg', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame('en-GB', $intake['conversation_language']);

        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'ok1',
            'text' => 'okay',
        ], 'ok-msg', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame('en-GB', $intake['conversation_language']);

        $stopped = $this->postJson('/api/v1/intakes/'.$intake['id'].'/voice-sessions/'.$session['id'].'/stop', $this->tokenA, [], 'voice-stop', 202);
        self::assertSame('closed', $stopped['status']);
        $queue = static::getContainer()->get(\App\Live\LiveGatewayCommandQueue::class);
        self::assertNotContains($session['id'], $queue->pending());
    }

    public function testCancelAndLockedIntake(): void
    {
        $intake = $this->createIntake($this->tokenA);
        $cancelled = $this->postJson('/api/v1/intakes/'.$intake['id'].'/cancel', $this->tokenA, [
            'expected_revision' => 0,
        ], 'cancel-1');
        self::assertSame('cancelled', $cancelled['status']);
        $this->client->jsonRequest(
            'POST',
            '/api/v1/intakes/'.$intake['id'].'/messages',
            ['expected_revision' => $cancelled['revision'], 'client_message_id' => 'x', 'text' => 'Hallo'],
            $this->auth($this->tokenA) + ['HTTP_IDEMPOTENCY_KEY' => 'locked-msg'],
        );
        self::assertResponseStatusCodeSame(409);
    }

    public function testSpokenCauseAndSpelledPostcodeAdvanceTheTree(): void
    {
        $intake = $this->createIntake($this->tokenA);
        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => 0,
            'client_message_id' => 'ledo-1',
            'text' => 'De keukenkraan druppelt sinds gisteren.',
        ], 'sp-1', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame('ask_cause', $intake['next_question']['id']);

        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'cause-1',
            'text' => 'De pakking is versleten',
        ], 'sp-2', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame('reported', $intake['fields']['cause']['state']);
        self::assertSame('De pakking is versleten', $intake['fields']['cause']['value']);
        self::assertSame('ask_address', $intake['next_question']['id']);

        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'pc-1',
            'text' => '1234 anton bernard',
        ], 'sp-3', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame('1234 AB', $intake['address']['postcode']);
        self::assertNull($intake['address']['house_number']);
        self::assertSame('address_ask_house_number', $intake['next_question']['id']);

        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'hn-1',
            'text' => 'huisnummer 12',
        ], 'sp-4', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame(12, $intake['address']['house_number']);
        self::assertCount(1, $intake['address']['candidates']);
        self::assertNull($intake['address']['candidates'][0]['addition']);
        self::assertStringStartsWith('address_confirm_', $intake['next_question']['id']);
        self::assertStringContainsString('Voorbeeldstraat 12', $intake['next_question']['text']);
    }

    public function testAskedLocationStoresFreeTextWhenNoKeywordMatches(): void
    {
        $intake = $this->createIntake($this->tokenA);
        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => 0,
            'client_message_id' => 'vague-1',
            'text' => 'er is iets kapot',
        ], 'loc-1', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame('ask_location', $intake['next_question']['id']);
        self::assertSame('reported', $intake['fields']['defect']['state']);

        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'loc-2',
            'text' => 'boven bij de trap',
        ], 'loc-2', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame('reported', $intake['fields']['location']['state']);
        self::assertSame('boven bij de trap', $intake['fields']['location']['value']);
        self::assertSame('ask_element', $intake['next_question']['id']);
    }

    public function testSpokenDigitWordsBecomeADutchPostcode(): void
    {
        $intake = $this->createIntake($this->tokenA);
        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => 0,
            'client_message_id' => 'ledo-w',
            'text' => 'De keukenkraan druppelt sinds gisteren.',
        ], 'sw-1', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'cause-w',
            'text' => 'Oorzaak onbekend',
        ], 'sw-2', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame('ask_address', $intake['next_question']['id']);

        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'pc-w',
            'text' => 'een twee drie vier anton bernard',
        ], 'sw-3', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame('1234 AB', $intake['address']['postcode']);
        self::assertSame('address_ask_house_number', $intake['next_question']['id']);
    }

    public function testSpokenAddressRejectionAsksForPostcodeAgain(): void
    {
        $intake = $this->spokenAddressConfirm($this->tokenA);
        self::assertStringStartsWith('address_confirm_', $intake['next_question']['id']);

        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'addr-no',
            'text' => 'Nee, ik heb de verkeerde postcode opgegeven. Ik wil even opnieuw beginnen met mijn postcode',
        ], 'addr-no-1', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame('ask_address', $intake['next_question']['id']);
        self::assertSame('missing', $intake['address']['verification_status']);
        self::assertNull($intake['address']['postcode']);
        self::assertSame([], $intake['address']['candidates']);
    }

    public function testSpokenAddressCorrectionLooksUpTheNewPostcode(): void
    {
        $intake = $this->spokenAddressConfirm($this->tokenA);
        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'addr-fix',
            'text' => 'Nee. De postcode is een twee drie vier anton bernard huisnummer twaalf',
        ], 'addr-fix-1', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame('1234 AB', $intake['address']['postcode']);
        self::assertSame(12, $intake['address']['house_number']);
        self::assertSame('unverified', $intake['address']['verification_status']);
        self::assertStringStartsWith('address_confirm_', $intake['next_question']['id']);
        self::assertStringContainsString('Voorbeeldstraat 12', $intake['next_question']['text']);
    }

    public function testSpokenKloptVerifiesAddressThenRecordsTheReport(): void
    {
        $intake = $this->spokenAddressConfirm($this->tokenA);
        self::assertStringStartsWith('address_confirm_', $intake['next_question']['id']);

        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'addr-klopt',
            'text' => 'Klopt',
        ], 'addr-klopt-1', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame('verified', $intake['address']['verification_status']);
        self::assertSame('ready_for_confirmation', $intake['status']);
        self::assertNotNull($intake['summary']);
        self::assertNull($intake['report_id']);
        self::assertSame($intake['summary']['id'], $intake['next_question']['id']);
        self::assertStringContainsString('Klopt dit?', $intake['next_question']['text']);
        self::assertStringContainsString('Keuken', $intake['next_question']['text']);

        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'summary-klopt',
            'text' => 'Ja, ik heb het al gecontroleerd',
        ], 'summary-klopt-1', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame('confirmed', $intake['status']);
        self::assertNotNull($intake['report_id']);
        self::assertSame('intake_confirmed', $intake['next_question']['id']);
        self::assertSame('De melding is vastgelegd.', $intake['next_question']['text']);
    }

    public function testSpokenOneShotLedoThenKloptAndJaRecordsTheReport(): void
    {
        $intake = $this->createIntake($this->tokenA);
        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => 0,
            'client_message_id' => 'one-shot-ledo',
            'text' => 'Een lekkende kraan in de badkamer. Oorzaak onbekend',
        ], 'one-shot-1', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame('ask_address', $intake['next_question']['id']);

        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'one-shot-pc',
            'text' => '1234 anton bernard huisnummer 12',
        ], 'one-shot-2', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertStringStartsWith('address_confirm_', $intake['next_question']['id']);

        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'one-shot-klopt',
            'text' => "Klopt\u{00A0}",
        ], 'one-shot-3', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame('verified', $intake['address']['verification_status']);
        self::assertNotNull($intake['summary']);
        self::assertNull($intake['report_id']);
        self::assertStringContainsString('Klopt dit?', $intake['next_question']['text']);
        self::assertStringContainsString('Badkamer', $intake['next_question']['text']);

        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'one-shot-ja',
            'text' => 'Ja, rond af',
        ], 'one-shot-4', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame('confirmed', $intake['status']);
        self::assertNotNull($intake['report_id']);
        self::assertSame('intake_confirmed', $intake['next_question']['id']);
        self::assertSame('De melding is vastgelegd.', $intake['next_question']['text']);
    }

    public function testSpokenYesOnTreeSummaryPlaceholderRecordsTheReport(): void
    {
        $intake = $this->spokenAddressConfirm($this->tokenA);
        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'addr-ja',
            'text' => 'Ja',
        ], 'placeholder-1', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertNotNull($intake['summary']);
        self::assertNull($intake['report_id']);

        $this->forceNextQuestion($intake['id'], 'terminal_summary', 'Ik vat het probleem samen zodat u het kunt controleren.');
        $intake = $this->getIntake($intake['id'], $this->tokenA);

        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'tree-summary-ja',
            'text' => 'Ja',
        ], 'placeholder-2', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame('confirmed', $intake['status']);
        self::assertNotNull($intake['report_id']);
        self::assertSame('De melding is vastgelegd.', $intake['next_question']['text']);
    }

    public function testSpokenYesOnPlaceholderWithoutSummaryRecordsTheReport(): void
    {
        $intake = $this->spokenAddressConfirm($this->tokenA);
        $this->forceVerifiedAddressStuckOnTreeSummary($intake['id']);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame('verified', $intake['address']['verification_status']);
        self::assertNull($intake['summary']);
        self::assertSame('terminal_summary', $intake['next_question']['id']);

        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $this->tokenA, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'stuck-ja',
            'text' => 'Ja',
        ], 'stuck-1', 202);
        $intake = $this->getIntake($intake['id'], $this->tokenA);
        self::assertSame('confirmed', $intake['status']);
        self::assertNotNull($intake['report_id']);
        self::assertSame('De melding is vastgelegd.', $intake['next_question']['text']);
    }

    /**
     * @return array<string, mixed>
     */
    private function spokenAddressConfirm(string $token): array
    {
        $intake = $this->createIntake($token);
        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $token, [
            'expected_revision' => 0,
            'client_message_id' => 'ledo-confirm',
            'text' => 'De keukenkraan druppelt sinds gisteren.',
        ], 'confirm-ledo', 202);
        $intake = $this->getIntake($intake['id'], $token);
        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $token, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'cause-confirm',
            'text' => 'Oorzaak onbekend',
        ], 'confirm-cause', 202);
        $intake = $this->getIntake($intake['id'], $token);
        $this->postJson('/api/v1/intakes/'.$intake['id'].'/messages', $token, [
            'expected_revision' => $intake['revision'],
            'client_message_id' => 'pc-confirm',
            'text' => '1234 anton bernard huisnummer 12',
        ], 'confirm-pc', 202);

        return $this->getIntake($intake['id'], $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function createIntake(string $token): array
    {
        return $this->postJson('/api/v1/intakes', $token, ['input_mode' => 'text'], 'create-'.bin2hex(random_bytes(3)), 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function getIntake(string $id, string $token): array
    {
        $this->client->request('GET', '/api/v1/intakes/'.$id, server: $this->auth($token));
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent() ?: '[]', true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function postJson(string $url, string $token, array $body, string $key, int $status = 200): array
    {
        $this->client->jsonRequest('POST', $url, $body, $this->auth($token) + ['HTTP_IDEMPOTENCY_KEY' => $key]);
        self::assertResponseStatusCodeSame($status);

        return json_decode($this->client->getResponse()->getContent() ?: '[]', true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function patchJson(string $url, string $token, array $body, string $key): array
    {
        $this->client->jsonRequest('PATCH', $url, $body, $this->auth($token) + ['HTTP_IDEMPOTENCY_KEY' => $key]);
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent() ?: '[]', true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, string>
     */
    private function auth(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    private function forceNextQuestion(string $intakeId, string $id, string $text): void
    {
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $intake = $entityManager->find(Intake::class, $intakeId);
        self::assertInstanceOf(Intake::class, $intake);
        $document = $intake->document();
        $document->nextQuestion = ['id' => $id, 'target' => null, 'text' => $text];
        $intake->replaceDocument($document);
        $entityManager->flush();
        $entityManager->clear();
    }

    private function forceVerifiedAddressStuckOnTreeSummary(string $intakeId): void
    {
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $intake = $entityManager->find(Intake::class, $intakeId);
        self::assertInstanceOf(Intake::class, $intake);
        $document = $intake->document();
        $candidate = $document->address['candidates'][0] ?? null;
        self::assertIsArray($candidate);
        $document->verifyAddress(
            (string) $document->address['lookup_id'],
            (string) $candidate['candidate_id'],
            (int) $document->address['address_revision'],
            'voice',
            'stuck-evidence',
        );
        $document->summary = null;
        $document->pendingSummaryQuestionId = null;
        $document->pendingAddressQuestionId = null;
        $document->nextQuestion = [
            'id' => 'terminal_summary',
            'target' => null,
            'text' => 'Ik vat het probleem samen zodat u het kunt controleren.',
        ];
        $intake->replaceDocument($document);
        $entityManager->flush();
        $entityManager->clear();
    }
}
