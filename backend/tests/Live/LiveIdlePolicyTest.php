<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Live\LiveIdlePolicy;
use PHPUnit\Framework\TestCase;

final class LiveIdlePolicyTest extends TestCase
{
    public function testPromptsAfterOneMinuteAndClosesAfterThree(): void
    {
        $policy = new LiveIdlePolicy(60, 180);
        self::assertNull($policy->action(0, false));
        self::assertNull($policy->action(59.9, false));
        self::assertSame(LiveIdlePolicy::PROMPT, $policy->action(60, false));
        self::assertNull($policy->action(60, true));
        self::assertNull($policy->action(179.9, true));
        self::assertSame(LiveIdlePolicy::CLOSE, $policy->action(180, true));
        self::assertSame(LiveIdlePolicy::CLOSE, $policy->action(180, false));
    }

    public function testEnvFallback(): void
    {
        $policy = LiveIdlePolicy::fromEnv('0', 'bad');
        self::assertSame(60, $policy->promptAfterSeconds());
        self::assertSame(180, $policy->closeAfterSeconds());

        $policy = LiveIdlePolicy::fromEnv('90', '10');
        self::assertSame(90, $policy->promptAfterSeconds());
        self::assertSame(210, $policy->closeAfterSeconds());
    }
}
