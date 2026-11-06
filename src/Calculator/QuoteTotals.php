<?php

declare(strict_types=1);

namespace Snt\Quotes\Calculator;

/**
 * Résultat figé du calcul d'un devis. Tous les montants sont arrondis à la précision de la devise.
 */
final class QuoteTotals
{
    /**
     * @param array<int, LineResult>                                    $lines        indexé comme CalcInput::$lines
     * @param array<int, array{te: float, ti: float}>                   $discounts    montant de chaque remise, indexé comme CalcInput::$discounts
     * @param array<string, array{rate: float, base: float, tax: float}> $taxBreakdown clé = taux formaté
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $discounts,
        public readonly float $productsTe,
        public readonly float $productsTi,
        public readonly float $discountsTe,
        public readonly float $discountsTi,
        public readonly float $shippingGrossTe,
        public readonly float $shippingTe,
        public readonly float $shippingTi,
        public readonly float $totalTe,
        public readonly float $totalTax,
        public readonly float $totalTi,
        public readonly array $taxBreakdown,
    ) {
    }

    /**
     * Les totaux sont-ils égaux à ceux d'une commande, au centime ?
     */
    public function matches(float $totalTe, float $totalTi, float $shippingTe, int $precision = 2): bool
    {
        $eq = static fn (float $a, float $b): bool => abs(round($a, $precision) - round($b, $precision)) < 0.5 / 10 ** $precision;

        return $eq($this->totalTe, $totalTe) && $eq($this->totalTi, $totalTi) && $eq($this->shippingTe, $shippingTe);
    }

    /**
     * Représentation sérialisable (stockage tax_breakdown, réponse AJAX).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'products_te' => $this->productsTe,
            'products_ti' => $this->productsTi,
            'discounts_te' => $this->discountsTe,
            'discounts_ti' => $this->discountsTi,
            'discounts' => $this->discounts,
            'shipping_gross_te' => $this->shippingGrossTe,
            'shipping_te' => $this->shippingTe,
            'shipping_ti' => $this->shippingTi,
            'total_te' => $this->totalTe,
            'total_tax' => $this->totalTax,
            'total_ti' => $this->totalTi,
            'tax_breakdown' => array_values($this->taxBreakdown),
            'lines' => array_map(static fn (LineResult $l): array => [
                'unit_net_te' => $l->unitNetTe,
                'unit_net_ti' => $l->unitNetTi,
                'total_te' => $l->totalTe,
                'total_ti' => $l->totalTi,
                'tax_rate' => $l->taxRate,
                'discount_percent' => $l->effectiveDiscountPercent,
                'below_cost' => $l->belowCost,
            ], $this->lines),
        ];
    }
}
