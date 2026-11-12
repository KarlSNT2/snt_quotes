<?php

declare(strict_types=1);

namespace Snt\Quotes\Service;

use Snt\Quotes\Calculator\QuoteTotals;

final class EditorResult
{
    /**
     * @param array<int, array<string, mixed>> $warnings
     * @param array<int, array<string, mixed>> $lines    lignes normalisées (prix catalogue, stock, taux…)
     */
    public function __construct(
        public readonly QuoteTotals $totals,
        public readonly array $warnings,
        public readonly bool $requiresApproval,
        public readonly float $maxDiscountPercent,
        public readonly array $lines,
        public readonly bool $taxExempt = false,
    ) {
    }
}
