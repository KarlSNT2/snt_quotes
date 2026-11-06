<?php

declare(strict_types=1);

namespace Snt\Quotes\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Snt\Quotes\Service\ReferenceFormatter;

final class ReferenceFormatterTest extends TestCase
{
    public function testDefaultPattern(): void
    {
        self::assertSame('DEV-ABOT-2026-00042', ReferenceFormatter::format('DEV-{SHOP}-{YEAR}-{NUM}', 'abot', 2026, 42));
    }

    public function testEmptyPatternFallsBackToDefault(): void
    {
        self::assertSame('DEV-DEF-2026-00001', ReferenceFormatter::format('  ', 'DEF', 2026, 1));
    }

    public function testNumberIsAlwaysPresent(): void
    {
        self::assertSame('Q-TAG-26-00007', ReferenceFormatter::format('Q-{SHOP}-{YY}', 'TAG', 2026, 7));
    }

    public function testShopCodeIsSanitized(): void
    {
        self::assertSame('DEV-EZSTORE-2026-00003', ReferenceFormatter::format('DEV-{SHOP}-{YEAR}-{NUM}', 'ez store!', 2026, 3));
    }

    public function testReferenceFitsColumn(): void
    {
        self::assertLessThanOrEqual(32, strlen(ReferenceFormatter::format(str_repeat('X', 40) . '{NUM}', 'A', 2026, 1)));
    }
}
