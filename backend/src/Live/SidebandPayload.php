<?php

declare(strict_types=1);

namespace App\Live;

use WebSocket\Message\Message;

/**
 * Decodes a GPT-Live sideband WebSocket frame.
 * Phrity Message::__toString() is the class name, not the JSON body.
 */
final class SidebandPayload
{
    /**
     * @return array<string, mixed>|null
     */
    public static function decode(mixed $message): ?array
    {
        if ($message instanceof Message) {
            $raw = $message->getContent();
        } elseif (is_object($message) && method_exists($message, 'getContent')) {
            $raw = (string) $message->getContent();
        } else {
            $raw = (string) $message;
        }
        if (str_starts_with($raw, 'WebSocket\\')) {
            return null;
        }
        $payload = json_decode($raw, true);

        return is_array($payload) ? $payload : null;
    }
}
