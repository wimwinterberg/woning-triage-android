<?php

declare(strict_types=1);

namespace App\Live;

interface GptLiveClient
{
    /**
     * Creates a GPT-Live WebRTC session via POST /v1/live/sessions with client delegation.
     *
     * @see https://developers.openai.com/api/docs/guides/voice-webrtc
     */
    public function createWebRtcSession(string $sdpOffer, string $instructions): LiveSessionResult;

    public function closeSession(string $providerSessionId): bool;
}
