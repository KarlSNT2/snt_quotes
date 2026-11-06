<?php

declare(strict_types=1);

namespace Snt\Quotes\Calculator;

use Snt\Quotes\Domain\ReductionType;

/**
 * Garde-fous commerciaux : remise au-delà du seuil (validation manager) et vente sous le prix d'achat.
 * Pur : prend le résultat du calcul, ne touche pas la base.
 */
final class DiscountGuard
{
    public function __construct(private readonly float $thresholdPercent)
    {
    }

    /**
     * Remise maximale constatée sur le devis, en % :
     * max(remise effective par ligne, remise globale rapportée au total produits).
     */
    public function maxDiscountPercent(CalcInput $input, QuoteTotals $totals): float
    {
        $max = 0.0;
        foreach ($totals->lines as $line) {
            $max = max($max, $line->effectiveDiscountPercent);
        }
        if ($totals->productsTe > 0) {
            $max = max($max, $totals->discountsTe / $totals->productsTe * 100);
        }
        foreach ($input->discounts as $discount) {
            if ($discount->type === ReductionType::Percent) {
                $max = max($max, $discount->value);
            }
        }

        return round($max, 2);
    }

    public function requiresApproval(CalcInput $input, QuoteTotals $totals): bool
    {
        return $this->thresholdPercent > 0 && $this->maxDiscountPercent($input, $totals) > $this->thresholdPercent;
    }

    /**
     * Index des lignes vendues sous le prix d'achat.
     *
     * @return int[]
     */
    public function linesBelowCost(QuoteTotals $totals): array
    {
        return array_keys(array_filter($totals->lines, static fn (LineResult $l): bool => $l->belowCost));
    }
}
