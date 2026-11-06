<?php

declare(strict_types=1);

namespace Snt\Quotes\Tests\Unit\Calculator;

use PHPUnit\Framework\TestCase;
use Snt\Quotes\Calculator\CalcDiscount;
use Snt\Quotes\Calculator\CalcInput;
use Snt\Quotes\Calculator\CalcLine;
use Snt\Quotes\Calculator\DiscountGuard;
use Snt\Quotes\Calculator\HalfUpRounder;
use Snt\Quotes\Calculator\QuoteCalculator;
use Snt\Quotes\Calculator\RoundingPolicy;
use Snt\Quotes\Domain\LineType;
use Snt\Quotes\Domain\ReductionType;

final class DiscountGuardTest extends TestCase
{
    private QuoteCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new QuoteCalculator(new RoundingPolicy(RoundingPolicy::ROUND_ITEM, 2, new HalfUpRounder()));
    }

    public function testPriceManuallyLoweredBelowCatalogCountsAsDiscount(): void
    {
        // Prix catalogue 100, prix saisi 80 → 20 % de remise effective.
        $input = new CalcInput([new CalcLine(LineType::Product, 1, 80.0, 20.0, catalogPriceTe: 100.0)]);
        $totals = $this->calculator->calculate($input);
        $guard = new DiscountGuard(15.0);

        self::assertSame(20.0, $guard->maxDiscountPercent($input, $totals));
        self::assertTrue($guard->requiresApproval($input, $totals));
    }

    public function testDiscountUnderThresholdNeedsNoApproval(): void
    {
        $input = new CalcInput(
            [CalcLine::product(1, 100.0, 20.0, ReductionType::Percent, 5.0)],
            [new CalcDiscount(ReductionType::Percent, 10.0)]
        );
        $totals = $this->calculator->calculate($input);

        self::assertFalse((new DiscountGuard(15.0))->requiresApproval($input, $totals));
    }

    public function testGlobalAmountDiscountIsConvertedToPercent(): void
    {
        $input = new CalcInput([CalcLine::product(1, 100.0, 20.0)], [new CalcDiscount(ReductionType::Amount, 25.0)]);
        $totals = $this->calculator->calculate($input);

        self::assertSame(25.0, (new DiscountGuard(15.0))->maxDiscountPercent($input, $totals));
    }

    public function testThresholdZeroDisablesApproval(): void
    {
        $input = new CalcInput([CalcLine::product(1, 100.0, 20.0, ReductionType::Percent, 90.0)]);

        self::assertFalse((new DiscountGuard(0.0))->requiresApproval($input, $this->calculator->calculate($input)));
    }

    public function testLinesBelowCostAreReported(): void
    {
        $input = new CalcInput([
            new CalcLine(LineType::Product, 1, 50.0, 20.0, wholesalePrice: 40.0),
            new CalcLine(LineType::Product, 1, 50.0, 20.0, ReductionType::Percent, 30.0, wholesalePrice: 40.0),
        ]);

        self::assertSame([1], (new DiscountGuard(15.0))->linesBelowCost($this->calculator->calculate($input)));
    }
}
