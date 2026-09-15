<?php

declare(strict_types=1);

namespace App\Live;

use App\Exception\ProviderUnavailableException;
use App\Exception\ValidationFailedException;
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
        } catch (\Throwable $exception) {
            throw new ProviderUnavailableException('GPT-Live kon geen sessie starten.');
        }

        $id = $payload['id'] ?? $payload['session']['id'] ?? null;
        $sdp = $payload['transport']['sdp'] ?? $payload['sdp'] ?? null;
        if (!is_string($id) || !is_string($sdp) || $sdp === '') {
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
}
