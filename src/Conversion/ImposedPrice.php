<?php

declare(strict_types=1);

namespace Snt\Quotes\Conversion;

/**
 * Prix à inscrire dans le SpecificPrice du panier technique pour que le cœur retrouve le prix net HT du devis.
 *
 * Le cœur (Product::priceCalculation, sans taxe) calcule : (prix spécifique + écotaxe) × (1 − remise de groupe).
 * On inverse : prix spécifique = prix net / (1 − remise de groupe) − écotaxe.
 * Pur et testable.
 */
final class ImposedPrice
{
    /**
     * @param float $targetNetTe          prix unitaire HT voulu (écotaxe comprise, comme dans le devis)
     * @param float $ecotaxTe             écotaxe HT ajoutée par le cœur (0 si PS_USE_ECOTAX désactivé)
     * @param float $groupReductionFactor remise de groupe en fraction (0,10 pour 10 %)
     */
    public static function specificPrice(float $targetNetTe, float $ecotaxTe, float $groupReductionFactor): float
    {
        if ($groupReductionFactor >= 1.0) {
            throw new \InvalidArgumentException('A 100% group reduction cannot be compensated.');
        }
        $base = $targetNetTe / (1 - max(0.0, $groupReductionFactor)) - max(0.0, $ecotaxTe);

        return round(max(0.0, $base), 6);
    }

    /**
     * Prix HT que le cœur recalculera à partir du prix spécifique (contrôle réciproque).
     */
    public static function corePrice(float $specificPrice, float $ecotaxTe, float $groupReductionFactor): float
    {
        return ($specificPrice + max(0.0, $ecotaxTe)) * (1 - max(0.0, $groupReductionFactor));
    }
}
