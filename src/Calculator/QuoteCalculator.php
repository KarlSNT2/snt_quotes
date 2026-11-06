<?php

declare(strict_types=1);

namespace Snt\Quotes\Calculator;

use Snt\Quotes\Domain\ReductionType;

/**
 * Calcul des montants d'un devis — aucune dépendance base de données.
 *
 * Ordre : prix unitaire → remise de ligne → arrondi selon PS_ROUND_TYPE → remises globales
 * (réparties au prorata HT entre les taux de TVA, comme une règle panier HT) → port → TVA par taux.
 *
 * Utilisé partout (éditeur BO, PDF, FO, conversion) : les totaux envoyés par le navigateur ne sont jamais persistés.
 */
final class QuoteCalculator
{
    /** Précision de stockage des prix unitaires (SpecificPrice, DECIMAL(20,6)). */
    public const UNIT_PRECISION = 6;

    public function __construct(private readonly RoundingPolicy $policy)
    {
    }

    public function calculate(CalcInput $input): QuoteTotals
    {
        $round = fn (float $v): float => $this->policy->round($v);
        $roundType = $this->policy->roundType;

        $lines = [];
        /** @var array<string, array{rate: float, te: float, ti: float, disc_te: float, disc_ti: float}> $byRate */
        $byRate = [];
        $productsTe = 0.0;
        $productsTi = 0.0;

        foreach ($input->lines as $i => $line) {
            if (!$line->type->hasAmount() || $line->qty <= 0) {
                $lines[$i] = LineResult::empty();
                continue;
            }
            $rate = $input->taxExempt ? 0.0 : max(0.0, $line->taxRate);
            $unitNetTe = self::unitNet($line);
            $unitNetTi = $unitNetTe * (1 + $rate / 100);

            switch ($roundType) {
                case RoundingPolicy::ROUND_ITEM:
                    $totalTe = $round($round($unitNetTe) * $line->qty);
                    $totalTi = $round($round($unitNetTi) * $line->qty);
                    break;
                case RoundingPolicy::ROUND_LINE:
                    $totalTe = $round($unitNetTe * $line->qty);
                    $totalTi = $round($unitNetTi * $line->qty);
                    break;
                default: // ROUND_TOTAL : pas d'arrondi intermédiaire
                    $totalTe = $unitNetTe * $line->qty;
                    $totalTi = $unitNetTi * $line->qty;
            }

            $reference = $line->catalogPriceTe ?? $line->unitPriceTe;
            $effectiveDiscount = $reference > 0 ? max(0.0, (1 - $unitNetTe / $reference) * 100) : 0.0;
            $belowCost = $line->wholesalePrice !== null && $line->wholesalePrice > 0 && $unitNetTe < $line->wholesalePrice;

            $lines[$i] = new LineResult(
                $unitNetTe,
                $round($unitNetTi),
                $round($totalTe),
                $round($totalTi),
                $rate,
                round($effectiveDiscount, 2),
                $belowCost
            );

            $key = self::rateKey($rate);
            $byRate[$key] ??= ['rate' => $rate, 'te' => 0.0, 'ti' => 0.0, 'disc_te' => 0.0, 'disc_ti' => 0.0];
            $byRate[$key]['te'] += $totalTe;
            $byRate[$key]['ti'] += $totalTi;
            $productsTe += $totalTe;
            $productsTi += $totalTi;
        }

        // Remises sur le total produits.
        $discounts = [];
        $discountsTe = 0.0;
        $discountsTi = 0.0;
        $remainingTe = $productsTe;
        foreach ($input->discounts as $d => $discount) {
            $amountTe = $discount->type === ReductionType::Percent
                ? $productsTe * min(100.0, max(0.0, $discount->value)) / 100
                : max(0.0, $discount->value);
            $amountTe = $round(min($amountTe, max(0.0, $remainingTe)));
            $remainingTe -= $amountTe;

            $amountTi = 0.0;
            if ($amountTe > 0 && $productsTe > 0) {
                foreach ($byRate as $key => $bucket) {
                    $shareTe = $amountTe * $bucket['te'] / $productsTe;
                    $shareTi = $shareTe * (1 + $bucket['rate'] / 100);
                    $byRate[$key]['disc_te'] += $shareTe;
                    $byRate[$key]['disc_ti'] += $shareTi;
                    $amountTi += $shareTi;
                }
            }
            $amountTi = $round($amountTi);
            $discounts[$d] = ['te' => $amountTe, 'ti' => $amountTi];
            $discountsTe += $amountTe;
            $discountsTi += $amountTi;
        }

        // Port.
        $shippingGross = $round(max(0.0, $input->shippingTe));
        $shippingTe = $input->freeShipping ? 0.0 : $round(max(0.0, $shippingGross - max(0.0, $input->shippingDiscountTe)));
        $shippingRate = $input->taxExempt ? 0.0 : max(0.0, $input->shippingTaxRate);
        $shippingTi = $round($shippingTe * (1 + $shippingRate / 100));

        $productsTe = $round($productsTe);
        $productsTi = $round($productsTi);
        $discountsTe = $round($discountsTe);
        $discountsTi = $round($discountsTi);
        $totalTe = $round($productsTe - $discountsTe + $shippingTe);
        $totalTi = $round($productsTi - $discountsTi + $shippingTi);
        $totalTax = $round($totalTi - $totalTe);

        return new QuoteTotals(
            $lines,
            $discounts,
            $productsTe,
            $productsTi,
            $discountsTe,
            $discountsTi,
            $shippingGross,
            $shippingTe,
            $shippingTi,
            $totalTe,
            $totalTax,
            $totalTi,
            $this->taxBreakdown($byRate, $shippingRate, $shippingTe, $shippingTi, $totalTax)
        );
    }

    /**
     * Prix unitaire HT net après remise de ligne, à 6 décimales.
     */
    public static function unitNet(CalcLine $line): float
    {
        $price = max(0.0, $line->unitPriceTe);
        $net = match ($line->reductionType) {
            ReductionType::Percent => $price * (1 - min(100.0, max(0.0, $line->reductionValue)) / 100),
            ReductionType::Amount => $price - max(0.0, $line->reductionValue),
            null => $price,
        };

        return round(max(0.0, $net), self::UNIT_PRECISION);
    }

    /**
     * @param array<string, array{rate: float, te: float, ti: float, disc_te: float, disc_ti: float}> $byRate
     *
     * @return array<string, array{rate: float, base: float, tax: float}>
     */
    private function taxBreakdown(array $byRate, float $shippingRate, float $shippingTe, float $shippingTi, float $totalTax): array
    {
        $round = fn (float $v): float => $this->policy->round($v);
        $out = [];
        foreach ($byRate as $key => $b) {
            $out[$key] = [
                'rate' => $b['rate'],
                'base' => $b['te'] - $b['disc_te'],
                'tax' => ($b['ti'] - $b['te']) - ($b['disc_ti'] - $b['disc_te']),
            ];
        }
        if ($shippingTe > 0) {
            $key = self::rateKey($shippingRate);
            $out[$key] ??= ['rate' => $shippingRate, 'base' => 0.0, 'tax' => 0.0];
            $out[$key]['base'] += $shippingTe;
            $out[$key]['tax'] += $shippingTi - $shippingTe;
        }
        if ($out === []) {
            return [];
        }
        foreach ($out as $key => $b) {
            $out[$key]['base'] = $round($b['base']);
            $out[$key]['tax'] = $round($b['tax']);
        }

        // Le reliquat d'arrondi va sur la plus grosse base pour que Σ TVA = TVA totale.
        $residue = $round($totalTax - array_sum(array_column($out, 'tax')));
        if ($residue != 0.0) {
            uasort($out, static fn (array $a, array $b): int => $b['base'] <=> $a['base']);
            $biggest = array_key_first($out);
            $out[$biggest]['tax'] = $round($out[$biggest]['tax'] + $residue);
        }
        ksort($out, SORT_NATURAL);

        return $out;
    }

    private static function rateKey(float $rate): string
    {
        return number_format($rate, 3, '.', '');
    }
}
