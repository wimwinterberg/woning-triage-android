<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Live\LiveGreeting;
use PHPUnit\Framework\TestCase;

final class LiveGreetingTest extends TestCase
{
    public function testSpokenGreetingIncludesOpeningQuestion(): void
    {
        $spoken = LiveGreeting::spoken('Wat is er aan de hand in uw woning?');
        self::assertStringContainsString('huurwoning', $spoken);
        self::assertStringContainsString('Wat is er aan de hand in uw woning?', $spoken);
        self::assertStringContainsString('Speak Dutch', LiveGreeting::instructions($spoken));
        self::assertStringContainsString('Greet immediately', LiveGreeting::instructions($spoken));
        self::assertStringContainsString($spoken, LiveGreeting::commentary($spoken));
    }
}
