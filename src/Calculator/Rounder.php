<?php

declare(strict_types=1);

namespace Snt\Quotes\Calculator;

interface Rounder
{
    public function round(float $value, int $precision): float;
}
