<?php

declare(strict_types=1);

namespace Snt\Quotes\Domain;

enum ReductionType: string
{
    case Percent = 'percent';
    case Amount = 'amount';
}
