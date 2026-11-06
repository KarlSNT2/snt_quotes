<?php

declare(strict_types=1);

namespace Snt\Quotes\Domain\Exception;

use Snt\Quotes\Domain\QuoteStatus;

final class InvalidTransitionException extends QuoteException
{
    public function __construct(QuoteStatus $from, QuoteStatus $to)
    {
        parent::__construct(
            'This action is not possible: the quote is "%from%" and cannot become "%to%".',
            ['%from%' => $from->label(), '%to%' => $to->label()]
        );
    }
}
