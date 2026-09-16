<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Agent\ResponsesLanguageSwitchAgent;
use App\Domain\LanguageSwitchTool;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ResponsesLanguageSwitchAgentTest extends TestCase
{
    public function testCallsResponsesWithSwitchLanguageTool(): void
    {
        $json = null;
        $http = $this->createMock(HttpClientInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'output' => [[
                'type' => 'function_call',
                'name' => LanguageSwitchTool::NAME,
                'arguments' => '{"language":"en-GB","apply_ui":true}',
            ]],
        ]);
        $response->method('getStatusCode')->willReturn(200);
        $http->expects(self::once())->method('request')->willReturnCallback(
            static function (string $method, string $url, array $options) use (&$json, $response): ResponseInterface {
                self::assertSame('POST', $method);
                self::assertStringEndsWith('/responses', $url);
                $json = $options['json'] ?? null;

                return $response;
            },
        );

        $agent = new ResponsesLanguageSwitchAgent($http, 'sk-test', 'gpt-4.1-mini');
        $tool = $agent->decide('Switch to English', 'nl-NL', 'nl-NL');

        self::assertInstanceOf(LanguageSwitchTool::class, $tool);
        self::assertSame('en-GB', $tool->language);
        self::assertTrue($tool->applyUi);
        self::assertSame('gpt-4.1-mini', $json['model'] ?? null);
        self::assertSame('auto', $json['tool_choice'] ?? null);
        self::assertSame(LanguageSwitchTool::NAME, $json['tools'][0]['name'] ?? null);
        self::assertSame('function', $json['tools'][0]['type'] ?? null);
        self::assertStringContainsString('Current conversation language: nl-NL', (string) ($json['input'] ?? ''));
        self::assertStringContainsString('Switch to English', (string) ($json['input'] ?? ''));
        self::assertStringContainsString('UI language offer pending: no', (string) ($json['input'] ?? ''));
        self::assertStringContainsString('switch_language', ResponsesLanguageSwitchAgent::instructions());
    }

    public function testReturnsNullWhenModelDoesNotCallTheTool(): void
    {
        $http = $this->createStub(HttpClientInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'ok']]]],
        ]);
        $response->method('getStatusCode')->willReturn(200);
        $http->method('request')->willReturn($response);

        $agent = new ResponsesLanguageSwitchAgent($http, 'sk-test');
        self::assertNull($agent->decide('De keukenkraan lekt', 'nl-NL', 'nl-NL'));
    }

    public function testMissingApiKeyDoesNotCallHttp(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::never())->method('request');
        $agent = new ResponsesLanguageSwitchAgent($http, '');
        self::assertNull($agent->decide('Switch to English', 'nl-NL', 'nl-NL'));
    }

    public function testHttpFailureDoesNotThrow(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->method('request')->willThrowException(new \RuntimeException('timeout'));
        $agent = new ResponsesLanguageSwitchAgent($http, 'sk-test');
        self::assertNull($agent->decide('Switch to English', 'nl-NL', 'nl-NL'));
    }
}
