<?php

declare(strict_types=1);

namespace App\Live;

/**
 * Records voice-session IDs that a long-running worker should attach to.
 * Production uses the console worker `woningtriage:live-gateway`.
 */
final class LiveGatewayCommandQueue
{
    public function __construct(private readonly string $directory)
    {
    }

    public function enqueue(string $voiceSessionId): void
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
        file_put_contents($this->directory.'/'.$voiceSessionId.'.json', json_encode([
            'voice_session_id' => $voiceSessionId,
            'queued_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<string>
     */
    public function pending(): array
    {
        $ids = [];
        foreach (glob($this->directory.'/*.json') ?: [] as $file) {
            $ids[] = basename($file, '.json');
        }

        return $ids;
    }

    public function ack(string $voiceSessionId): void
    {
        $path = $this->directory.'/'.$voiceSessionId.'.json';
        if (is_file($path)) {
            unlink($path);
        }
    }
}
