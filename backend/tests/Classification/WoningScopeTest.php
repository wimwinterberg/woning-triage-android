<?php

declare(strict_types=1);

namespace App\Tests\Classification;

use App\Classification\WoningScope;
use PHPUnit\Framework\TestCase;

final class WoningScopeTest extends TestCase
{
    public function testMatchesHomeBuildingTypesOnly(): void
    {
        self::assertTrue(WoningScope::matchesGebouwtype('woning'));
        self::assertTrue(WoningScope::matchesGebouwtype('eengezinswoning'));
        self::assertFalse(WoningScope::matchesGebouwtype('complex'));
        self::assertFalse(WoningScope::matchesGebouwtype('galerijflat'));
    }
}
