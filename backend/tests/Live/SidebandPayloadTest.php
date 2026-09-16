<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Live\SidebandPayload;
use PHPUnit\Framework\TestCase;
use WebSocket\Message\Text;

final class SidebandPayloadTest extends TestCase
{
    public function testDecodesJsonFromPhrityMessageContent(): void
    {
        $message = new Text('{"type":"session.delegation.created","delegation":{"target":"client"}}');
        $payload = SidebandPayload::decode($message);
        self::assertIsArray($payload);
        self::assertSame('session.delegation.created', $payload['type']);
        self::assertSame('WebSocket\\Message\\Text', (string) $message);
    }

    public function testRejectsClassNameCast(): void
    {
        self::assertNull(SidebandPayload::decode('WebSocket\\Message\\Text'));
    }
}
