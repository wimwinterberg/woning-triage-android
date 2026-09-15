<?php

declare(strict_types=1);

namespace App\Live;

/**
 * Test/dev stand-in. A fake SDP is never a successful live GPT-Live proof.
 */
final class FakeGptLiveClient implements GptLiveClient
{
    public function createWebRtcSession(string $sdpOffer, string $instructions): LiveSessionResult
    {
        if (trim($sdpOffer) === '') {
            throw new \App\Exception\ValidationFailedException('SDP-offer ontbreekt.');
        }

        return new LiveSessionResult(
            'prov_fake_'.bin2hex(random_bytes(4)),
            "v=0\r\no=- 0 0 IN IP4 127.0.0.1\r\ns=WoningtriageFake\r\nt=0 0\r\n",
            true,
        );
    }

    public function closeSession(string $providerSessionId): bool
    {
        return true;
    }
}
