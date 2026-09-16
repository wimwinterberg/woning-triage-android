<?php

declare(strict_types=1);

namespace App\Live;

use App\Exception\ProviderUnavailableException;
use App\Exception\ValidationFailedException;
use App\Http\OperationalLog;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Official GPT-Live HTTP adapter. Does not use the older Realtime call endpoints.
 */
final class HttpGptLiveClient implements GptLiveClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $apiKey = '',
        private readonly string $model = 'gpt-live-1',
        private readonly string $baseUrl = 'https://api.openai.com/v1',
        private readonly string $voice = 'marin',
        private readonly string $speed = '1.0',
    ) {
    }

    public function createWebRtcSession(string $sdpOffer, string $instructions): LiveSessionResult
    {
        if (($this->apiKey ?? '') === '') {
            throw new ProviderUnavailableException('GPT-Live is niet geconfigureerd (OPENAI_API_KEY ontbreekt).');
        }
        if (trim($sdpOffer) === '') {
            throw new ValidationFailedException('SDP-offer ontbreekt.');
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/live/sessions', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                    'OpenAI-Beta' => 'live=v1',
                ],
                'json' => [
                    'session' => [
                        'model' => $this->model,
                        'instructions' => $instructions,
                        'audio' => [
                            'output' => [
                                'voice' => $this->voiceName(),
                                'speed' => $this->speedValue(),
                            ],
                        ],
                        'delegation' => ['type' => 'client'],
                    ],
                    'transport' => [
                        'type' => 'webrtc',
                        'sdp' => $sdpOffer,
                    ],
                ],
                'timeout' => 20,
            ]);
            $payload = $response->toArray(false);
            $status = $response->getStatusCode();
        } catch (\Throwable $exception) {
            $this->logCreateFailure('exception', 0, [], $exception);
            throw new ProviderUnavailableException('GPT-Live kon geen sessie starten.');
        }

        $id = $payload['id'] ?? $payload['session']['id'] ?? null;
        $sdp = $payload['transport']['sdp'] ?? $payload['sdp'] ?? null;
        if (!is_string($id) || !is_string($sdp) || $sdp === '') {
            $this->logCreateFailure('incomplete', $status, $payload);
            throw new ProviderUnavailableException('GPT-Live gaf een onvolledig sessieantwoord.');
        }

        return new LiveSessionResult($id, $sdp, false);
    }

    public function closeSession(string $providerSessionId): bool
    {
        if ($this->apiKey === '' || $providerSessionId === '') {
            return false;
        }
        try {
            $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/live/sessions/'.$providerSessionId.'/close', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'OpenAI-Beta' => 'live=v1',
                ],
                'timeout' => 10,
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function voiceName(): string
    {
        $voice = strtolower(trim($this->voice));

        return $voice !== '' ? $voice : 'marin';
    }

    public function speedValue(): float
    {
        $speed = (float) str_replace(',', '.', trim($this->speed));
        if ($speed < 0.8 || $speed > 1.2) {
            return 1.0;
        }

        return round($speed, 2);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function logCreateFailure(string $reason, int $status, array $payload, ?\Throwable $exception = null): void
    {
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
        $openaiType = is_string($error['type'] ?? null) ? $error['type'] : '-';
        $openaiCode = is_string($error['code'] ?? null) ? $error['code'] : '-';
        $exceptionClass = $exception !== null ? $exception::class : '-';
        $exceptionMessage = $exception !== null ? $this->clip($exception->getMessage()) : '-';
        OperationalLog::write(sprintf(
            'GPT-Live session create failed reason=%s http_status=%d openai_type=%s openai_code=%s keys=%s exception=%s message=%s',
            $reason,
            $status,
            $openaiType,
            $openaiCode,
            implode(',', array_keys($payload)),
            $exceptionClass,
            $exceptionMessage,
        ));
    }

    private function clip(string $text): string
    {
        $text = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $text) ?? $text;
        if (mb_strlen($text) <= 180) {
            return $text;
        }

        return mb_substr($text, 0, 180).'…';
    }
}
