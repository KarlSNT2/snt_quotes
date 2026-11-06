<?php

declare(strict_types=1);

namespace Snt\Quotes\Service;

final class EditorResultWithQuote
{
    public function __construct(
        public readonly \SntQuote $quote,
        public readonly EditorResult $result,
    ) {
    }
}
