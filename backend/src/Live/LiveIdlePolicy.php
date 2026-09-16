<?php

declare(strict_types=1);

namespace App\Live;

/**
 * GPT-Live is billed per minute. Silence must nudge, then close the session.
 */
final class LiveIdlePolicy
{
    public const PROMPT = 'prompt';
    public const CLOSE = 'close';

    public function __construct(
        private readonly int $promptAfterSeconds = 60,
        private readonly int $closeAfterSeconds = 180,
    ) {
        if ($this->promptAfterSeconds < 1 || $this->closeAfterSeconds <= $this->promptAfterSeconds) {
            throw new \InvalidArgumentException('Idle close must be later than the idle prompt.');
        }
    }

    public static function fromEnv(string $promptSeconds, string $closeSeconds): self
    {
        $prompt = (int) $promptSeconds;
        $close = (int) $closeSeconds;
        if ($prompt < 1) {
            $prompt = 60;
        }
        if ($close <= $prompt) {
            $close = $prompt + 120;
        }

        return new self($prompt, $close);
    }

    /**
     * @return self::PROMPT|self::CLOSE|null
     */
    public function action(float $idleSeconds, bool $alreadyPrompted): ?string
    {
        if ($idleSeconds >= $this->closeAfterSeconds) {
            return self::CLOSE;
        }
        if (!$alreadyPrompted && $idleSeconds >= $this->promptAfterSeconds) {
            return self::PROMPT;
        }

        return null;
    }

    public function promptAfterSeconds(): int
    {
        return $this->promptAfterSeconds;
    }

    public function closeAfterSeconds(): int
    {
        return $this->closeAfterSeconds;
    }
}
