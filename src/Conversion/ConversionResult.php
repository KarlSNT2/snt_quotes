<?php

declare(strict_types=1);

namespace Snt\Quotes\Conversion;

final class ConversionResult
{
    /**
     * @param string[] $warnings
     */
    public function __construct(
        public readonly int $idOrder,
        public readonly string $orderReference,
        public readonly array $warnings = [],
    ) {
    }
}
