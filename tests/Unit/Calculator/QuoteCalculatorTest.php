<?php

declare(strict_types=1);

namespace Snt\Quotes\Tests\Unit\Calculator;

use PHPUnit\Framework\TestCase;
use Snt\Quotes\Calculator\CalcDiscount;
use Snt\Quotes\Calculator\CalcInput;
use Snt\Quotes\Calculator\CalcLine;
use Snt\Quotes\Calculator\HalfUpRounder;
use Snt\Quotes\Calculator\QuoteCalculator;
use Snt\Quotes\Calculator\RoundingPolicy;
use Snt\Quotes\Domain\LineType;
use Snt\Quotes\Domain\ReductionType;

final class QuoteCalculatorTest extends TestCase
{
    private function calculator(int $roundType = RoundingPolicy::ROUND_ITEM): QuoteCalculator
    {
        return new QuoteCalculator(new RoundingPolicy($roundType, 2, new HalfUpRounder()));
    }

    public function testFrenchCustomerWithVatAndShipping(): void
    {
        $totals = $this->calculator()->calculate(new CalcInput(
            [CalcLine::product(2, 100.0, 20.0)],
            [],
            10.0,
            20.0
        ));

        self::assertSame(200.0, $totals->productsTe);
        self::assertSame(240.0, $totals->productsTi);
        self::assertSame(10.0, $totals->shippingTe);
        self::assertSame(12.0, $totals->shippingTi);
        self::assertSame(210.0, $totals->totalTe);
        self::assertSame(42.0, $totals->totalTax);
        self::assertSame(252.0, $totals->totalTi);
        self::assertSame([['rate' => 20.0, 'base' => 210.0, 'tax' => 42.0]], array_values($totals->taxBreakdown));
    }

    public function testLineReductionsPercentAndAmount(): void
    {
        $totals = $this->calculator()->calculate(new CalcInput([
            CalcLine::product(1, 100.0, 20.0, ReductionType::Percent, 10.0),
            CalcLine::product(2, 50.0, 20.0, ReductionType::Amount, 5.0),
        ]));

        self::assertSame(90.0, $totals->lines[0]->unitNetTe);
        self::assertSame(45.0, $totals->lines[1]->unitNetTe);
        self::assertSame(90.0, $totals->lines[1]->totalTe);
        self::assertSame(180.0, $totals->productsTe);
        self::assertSame(216.0, $totals->totalTi);
        self::assertSame(10.0, $totals->lines[0]->effectiveDiscountPercent);
    }

    public function testAmountReductionCannotGoNegative(): void
    {
        $totals = $this->calculator()->calculate(new CalcInput([
            CalcLine::product(1, 10.0, 20.0, ReductionType::Amount, 15.0),
        ]));

        self::assertSame(0.0, $totals->lines[0]->unitNetTe);
        self::assertSame(0.0, $totals->totalTi);
    }

    /**
     * 3 × 0,333333 € HT à 20 % : chaque mode d'arrondi PrestaShop donne un résultat différent.
     */
    public function testRoundingModesFollowPrestaShop(): void
    {
        $input = new CalcInput([CalcLine::product(3, 0.333333, 20.0)]);

        $item = $this->calculator(RoundingPolicy::ROUND_ITEM)->calculate($input);
        self::assertSame(0.99, $item->productsTe);   // 0,33 × 3
        self::assertSame(1.2, $item->productsTi);    // 0,40 × 3

        $line = $this->calculator(RoundingPolicy::ROUND_LINE)->calculate($input);
        self::assertSame(1.0, $line->productsTe);    // round(0,999999)
        self::assertSame(1.2, $line->productsTi);    // round(1,1999992)

        $total = $this->calculator(RoundingPolicy::ROUND_TOTAL)->calculate($input);
        self::assertSame(1.0, $total->productsTe);
        self::assertSame(1.2, $total->totalTi);
    }

    public function testRoundTotalAccumulatesBeforeRounding(): void
    {
        // 3 lignes à 0,334 HT : par ligne 0,33 × 3 = 0,99 ; sur le total 1,002 → 1,00.
        $lines = array_fill(0, 3, CalcLine::product(1, 0.334, 0.0));
        self::assertSame(0.99, $this->calculator(RoundingPolicy::ROUND_LINE)->calculate(new CalcInput($lines))->totalTe);
        self::assertSame(1.0, $this->calculator(RoundingPolicy::ROUND_TOTAL)->calculate(new CalcInput($lines))->totalTe);
    }

    public function testIntraCommunityB2bIsTaxExempt(): void
    {
        $totals = $this->calculator()->calculate(new CalcInput(
            [CalcLine::product(1, 1000.0, 20.0)],
            [],
            25.0,
            20.0,
            taxExempt: true
        ));

        self::assertSame(1025.0, $totals->totalTe);
        self::assertSame(0.0, $totals->totalTax);
        self::assertSame(1025.0, $totals->totalTi);
        self::assertSame(0.0, $totals->lines[0]->taxRate);
    }

    public function testGlobalDiscountIsSplitAcrossTaxRates(): void
    {
        $totals = $this->calculator()->calculate(new CalcInput(
            [CalcLine::product(1, 100.0, 20.0), CalcLine::product(1, 100.0, 5.5)],
            [new CalcDiscount(ReductionType::Percent, 10.0, 'Remise globale')]
        ));

        self::assertSame(20.0, $totals->discountsTe);
        self::assertSame(22.55, $totals->discountsTi);
        self::assertSame(180.0, $totals->totalTe);
        self::assertSame(202.95, $totals->totalTi);
        self::assertSame(22.95, $totals->totalTax);

        $breakdown = array_values($totals->taxBreakdown);
        self::assertCount(2, $breakdown);
        $byRate = $totals->taxBreakdown;
        self::assertSame(90.0, $byRate['20.000']['base']);
        self::assertSame(18.0, $byRate['20.000']['tax']);
        self::assertSame(90.0, $byRate['5.500']['base']);
        self::assertSame(4.95, $byRate['5.500']['tax']);
    }

    public function testVoucherIsCappedToRemainingProducts(): void
    {
        $totals = $this->calculator()->calculate(new CalcInput(
            [CalcLine::product(1, 50.0, 20.0)],
            [new CalcDiscount(ReductionType::Amount, 30.0), new CalcDiscount(ReductionType::Amount, 30.0)],
            10.0,
            20.0
        ));

        self::assertSame(30.0, $totals->discounts[0]['te']);
        self::assertSame(20.0, $totals->discounts[1]['te']);
        self::assertSame(10.0, $totals->totalTe); // seul le port reste
        self::assertSame(12.0, $totals->totalTi);
    }

    public function testFreeShippingAndShippingDiscount(): void
    {
        $free = $this->calculator()->calculate(new CalcInput([CalcLine::product(1, 100.0, 20.0)], [], 15.0, 20.0, freeShipping: true));
        self::assertSame(15.0, $free->shippingGrossTe);
        self::assertSame(0.0, $free->shippingTe);
        self::assertSame(120.0, $free->totalTi);

        $partial = $this->calculator()->calculate(new CalcInput([CalcLine::product(1, 100.0, 20.0)], [], 15.0, 20.0, 5.0));
        self::assertSame(10.0, $partial->shippingTe);
        self::assertSame(12.0, $partial->shippingTi);
        self::assertSame(132.0, $partial->totalTi);
    }

    public function testSectionsAndCommentsHaveNoAmount(): void
    {
        $totals = $this->calculator()->calculate(new CalcInput([
            CalcLine::section(),
            new CalcLine(LineType::Comment, 0, 0.0, 0.0),
            new CalcLine(LineType::Free, 1, 150.0, 20.0),
        ]));

        self::assertSame(0.0, $totals->lines[0]->totalTe);
        self::assertSame(0.0, $totals->lines[1]->totalTe);
        self::assertSame(150.0, $totals->totalTe);
        self::assertSame(180.0, $totals->totalTi);
    }

    public function testTaxBreakdownSumsToTotalTax(): void
    {
        $lines = [];
        foreach ([[3, 19.99, 20.0], [7, 4.17, 5.5], [1, 129.9, 10.0], [11, 0.83, 20.0]] as [$qty, $price, $rate]) {
            $lines[] = CalcLine::product($qty, $price, $rate, ReductionType::Percent, 7.5);
        }
        foreach ([RoundingPolicy::ROUND_ITEM, RoundingPolicy::ROUND_LINE, RoundingPolicy::ROUND_TOTAL] as $mode) {
            $totals = $this->calculator($mode)->calculate(new CalcInput($lines, [new CalcDiscount(ReductionType::Amount, 12.34)], 9.9, 20.0));
            self::assertEqualsWithDelta($totals->totalTax, array_sum(array_column($totals->taxBreakdown, 'tax')), 0.001, 'mode ' . $mode);
            self::assertEqualsWithDelta($totals->totalTe, array_sum(array_column($totals->taxBreakdown, 'base')), 0.011, 'mode ' . $mode);
            self::assertEqualsWithDelta($totals->totalTi, $totals->totalTe + $totals->totalTax, 0.001, 'mode ' . $mode);
        }
    }

    public function testMatchesComparesToTheCent(): void
    {
        $totals = $this->calculator()->calculate(new CalcInput([CalcLine::product(1, 99.99, 20.0)], [], 5.0, 20.0));

        self::assertTrue($totals->matches(104.99, 125.99, 5.0));
        self::assertFalse($totals->matches(104.99, 126.0, 5.0));
    }

    public function testUnknownRoundTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RoundingPolicy(9, 2, new HalfUpRounder());
    }
}
