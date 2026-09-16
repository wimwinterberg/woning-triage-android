<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Agent\FakeLanguageSwitchAgent;
use App\Agent\LanguageSwitchAgentFactory;
use App\Agent\ResponsesLanguageSwitchAgent;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LanguageSwitchAgentFactoryTest extends TestCase
{
    public function testUsesFakeWhenApiKeyIsMissing(): void
    {
        $http = new ResponsesLanguageSwitchAgent($this->createStub(HttpClientInterface::class), 'sk-test');
        $fake = new FakeLanguageSwitchAgent();
        self::assertSame($fake, LanguageSwitchAgentFactory::create($http, $fake, ''));
    }

    public function testUsesResponsesAgentWhenApiKeyIsSet(): void
    {
        $http = new ResponsesLanguageSwitchAgent($this->createStub(HttpClientInterface::class), 'sk-test');
        $fake = new FakeLanguageSwitchAgent();
        self::assertSame($http, LanguageSwitchAgentFactory::create($http, $fake, 'sk-test'));
    }
}
