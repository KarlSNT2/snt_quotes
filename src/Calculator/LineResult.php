<?php

declare(strict_types=1);

namespace Snt\Quotes\Calculator;

final class LineResult
{
    public function __construct(
        public readonly float $unitNetTe,
        public readonly float $unitNetTi,
        public readonly float $totalTe,
        public readonly float $totalTi,
        public readonly float $taxRate,
        /** Remise effective en % par rapport au prix catalogue (0 si inconnu). */
        public readonly float $effectiveDiscountPercent,
        public readonly bool $belowCost,
    ) {
    }

    public static function empty(): self
    {
        return new self(0.0, 0.0, 0.0, 0.0, 0.0, 0.0, false);
    }
}
