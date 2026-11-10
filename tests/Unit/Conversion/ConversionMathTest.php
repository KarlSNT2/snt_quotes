<?php

declare(strict_types=1);

namespace Snt\Quotes\Tests\Unit\Conversion;

use PHPUnit\Framework\TestCase;
use Snt\Quotes\Conversion\ImposedPrice;
use Snt\Quotes\Conversion\TotalsComparator;

final class ConversionMathTest extends TestCase
{
    public function testImposedPriceWithoutAdjustment(): void
    {
        self::assertSame(149.9, ImposedPrice::specificPrice(149.9, 0.0, 0.0));
    }

    public function testGroupReductionIsCompensated(): void
    {
        // Groupe B2B à -10 % : le cœur recalculera exactement le prix du devis.
        $sp = ImposedPrice::specificPrice(90.0, 0.0, 0.10);
        self::assertSame(100.0, $sp);
        self::assertEqualsWithDelta(90.0, ImposedPrice::corePrice($sp, 0.0, 0.10), 0.000001);
    }

    public function testEcotaxIsNotCountedTwice(): void
    {
        $sp = ImposedPrice::specificPrice(52.5, 2.5, 0.0);
        self::assertSame(50.0, $sp);
        self::assertEqualsWithDelta(52.5, ImposedPrice::corePrice($sp, 2.5, 0.0), 0.000001);
    }

    public function testRoundTripOnAwkwardValues(): void
    {
        foreach ([[0.333333, 0.0, 0.07], [1234.56, 0.12, 0.15], [9.99, 0.3, 0.025]] as [$target, $ecotax, $group]) {
            $sp = ImposedPrice::specificPrice($target, $ecotax, $group);
            self::assertEqualsWithDelta($target, ImposedPrice::corePrice($sp, $ecotax, $group), 0.00001);
        }
    }

    public function testFullGroupReductionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ImposedPrice::specificPrice(10.0, 0.0, 1.0);
    }

    public function testIdenticalTotalsHaveNoDifference(): void
    {
        $totals = ['total_te' => 104.99, 'total_ti' => 125.99, 'shipping_te' => 5.0, 'shipping_ti' => 6.0];
        self::assertSame([], TotalsComparator::differences($totals, $totals + []));
        self::assertSame([], TotalsComparator::differences($totals, ['total_te' => 104.990001, 'total_ti' => 125.9899, 'shipping_te' => 5.0, 'shipping_ti' => 6.0]));
    }

    public function testOneCentDifferenceIsReported(): void
    {
        $diff = TotalsComparator::differences(
            ['total_te' => 100.0, 'total_ti' => 120.0, 'shipping_te' => 10.0],
            ['total_te' => 100.0, 'total_ti' => 120.01, 'shipping_te' => 10.0]
        );
        self::assertSame(['total_ti' => ['expected' => 120.0, 'actual' => 120.01]], $diff);
    }
}
