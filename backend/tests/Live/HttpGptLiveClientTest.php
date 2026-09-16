<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Live\HttpGptLiveClient;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class HttpGptLiveClientTest extends TestCase
{
    public function testPinsOutputVoiceWhenCreatingASession(): void
    {
        $json = null;
        $http = $this->createMock(HttpClientInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'id' => 'sess_1',
            'transport' => ['sdp' => "v=0\r\n"],
        ]);
        $response->method('getStatusCode')->willReturn(200);
        $http->expects(self::once())->method('request')->willReturnCallback(
            static function (string $method, string $url, array $options) use (&$json, $response): ResponseInterface {
                self::assertSame('POST', $method);
                self::assertStringEndsWith('/live/sessions', $url);
                $json = $options['json'] ?? null;

                return $response;
            },
        );

        $client = new HttpGptLiveClient($http, 'sk-test');
        $result = $client->createWebRtcSession("v=0\r\n", 'Help the resident.');

        self::assertSame('sess_1', $result->providerSessionId);
        self::assertSame('marin', $json['session']['audio']['output']['voice'] ?? null);
        self::assertArrayNotHasKey('speed', $json['session']['audio']['output'] ?? []);
        self::assertArrayNotHasKey('format', $json['session']['audio'] ?? []);
        self::assertSame('marin', $client->voiceName());
    }

    public function testEmptyVoiceFallsBackToMarin(): void
    {
        $client = new HttpGptLiveClient($this->createStub(HttpClientInterface::class), 'sk-test', 'gpt-live-1', 'https://api.openai.com/v1', '  ');
        self::assertSame('marin', $client->voiceName());
    }

    public function testOpenAiErrorPayloadIsUnavailable(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'unknown_parameter',
                'param' => 'session.audio.output.speed',
                'message' => 'Unknown parameter: session.audio.output.speed',
            ],
        ]);
        $response->method('getStatusCode')->willReturn(400);
        $http->method('request')->willReturn($response);

        $client = new HttpGptLiveClient($http, 'sk-test');
        $this->expectException(\App\Exception\ProviderUnavailableException::class);
        $this->expectExceptionMessage('GPT-Live gaf een onvolledig sessieantwoord.');
        $client->createWebRtcSession("v=0\r\n", 'Help the resident.');
    }

    public function testTransportFailureIsUnavailable(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->method('request')->willThrowException(new \RuntimeException('timeout'));

        $client = new HttpGptLiveClient($http, 'sk-test');
        $this->expectException(\App\Exception\ProviderUnavailableException::class);
        $this->expectExceptionMessage('GPT-Live kon geen sessie starten.');
        $client->createWebRtcSession("v=0\r\n", 'Help the resident.');
    }
}
