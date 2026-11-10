<?php

declare(strict_types=1);

namespace Snt\Quotes\Conversion;

/**
 * Compare les totaux du devis à ceux calculés par le cœur (panier ou commande), au centime.
 */
final class TotalsComparator
{
    /**
     * @param array{total_te: float, total_ti: float, shipping_te: float, shipping_ti?: float} $expected
     * @param array{total_te: float, total_ti: float, shipping_te: float, shipping_ti?: float} $actual
     *
     * @return array<string, array{expected: float, actual: float}> écarts (vide si identiques)
     */
    public static function differences(array $expected, array $actual, int $precision = 2): array
    {
        $out = [];
        foreach (['total_te', 'total_ti', 'shipping_te', 'shipping_ti'] as $key) {
            if (!isset($expected[$key], $actual[$key])) {
                continue;
            }
            $a = round((float) $expected[$key], $precision);
            $b = round((float) $actual[$key], $precision);
            if (abs($a - $b) >= 0.5 / 10 ** $precision) {
                $out[$key] = ['expected' => $a, 'actual' => $b];
            }
        }

        return $out;
    }
}
