<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Live\FakeGptLiveClient;
use App\Live\FakeSdp;
use PHPUnit\Framework\TestCase;

final class FakeSdpTest extends TestCase
{
    public function testAnswerKeepsOfferMLineOrder(): void
    {
        $offer = implode("\r\n", [
            'v=0',
            'm=audio 9 UDP/TLS/RTP/SAVPF 111',
            'a=mid:0',
            'm=application 9 UDP/DTLS/SCTP webrtc-datachannel',
            'a=mid:1',
            '',
        ]);
        $answer = FakeSdp::answerFromOffer($offer);
        self::assertStringContainsString('s=WoningtriageFake', $answer);
        $kinds = [];
        foreach (preg_split("/\R/", $answer) ?: [] as $line) {
            if (str_starts_with($line, 'm=')) {
                $kinds[] = substr(explode(' ', $line)[0], 2);
            }
        }
        self::assertSame(['audio', 'application'], $kinds);
    }

    public function testFakeClientUsesMatchingAnswer(): void
    {
        $offer = "v=0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\na=mid:0\r\n";
        $result = (new FakeGptLiveClient())->createWebRtcSession($offer, 'instructions');
        self::assertTrue($result->fake);
        self::assertStringStartsWith('prov_fake_', $result->providerSessionId);
        self::assertStringContainsString('m=audio', $result->sdpAnswer);
    }
}
