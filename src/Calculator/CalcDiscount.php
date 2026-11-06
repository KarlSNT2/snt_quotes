<?php

declare(strict_types=1);

namespace Snt\Quotes\Calculator;

use Snt\Quotes\Domain\ReductionType;

/**
 * Remise sur le total produits (remise globale ou bon de réduction), exprimée HT.
 * Un pourcentage s'applique au total produits après remises de ligne.
 */
final class CalcDiscount
{
    public function __construct(
        public readonly ReductionType $type,
        public readonly float $value,
        public readonly string $label = '',
    ) {
    }
}
