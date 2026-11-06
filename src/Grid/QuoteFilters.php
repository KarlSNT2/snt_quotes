<?php

declare(strict_types=1);

namespace Snt\Quotes\Grid;

use PrestaShop\PrestaShop\Core\Search\Filters;

final class QuoteFilters extends Filters
{
    protected $filterId = QuoteGridDefinitionFactory::GRID_ID;

    /**
     * @return array<string, mixed>
     */
    public static function getDefaults()
    {
        return [
            'limit' => 50,
            'offset' => 0,
            'orderBy' => 'date_add',
            'sortOrder' => 'desc',
            'filters' => [],
        ];
    }
}
