<?php

declare(strict_types=1);

use Snt\Quotes\Domain\LineType;
use Snt\Quotes\Domain\ReductionType;

if (!defined('_PS_VERSION_')) {
    exit;
}

class SntQuoteLine extends ObjectModel
{
    /** @var int */
    public $id_snt_quote;
    /** @var string */
    public $line_type = 'product';
    /** @var int|null */
    public $id_product;
    /** @var int|null */
    public $id_product_attribute;
    /** @var string|null */
    public $label;
    /** @var string|null */
    public $reference;
    /** @var int */
    public $qty = 0;
    /** @var float|null */
    public $catalog_price_te;
    /** @var float */
    public $unit_price_te = 0.0;
    /** @var string|null */
    public $reduction_type;
    /** @var float|null */
    public $reduction_value;
    /** @var float */
    public $unit_net_te = 0.0;
    /** @var float */
    public $total_te = 0.0;
    /** @var int|null */
    public $id_tax_rules_group;
    /** @var float */
    public $tax_rate = 0.0;
    /** @var float */
    public $ecotax = 0.0;
    /** @var float|null */
    public $wholesale_price;
    /** @var string|null */
    public $comment;
    /** @var int */
    public $position = 0;

    /** @var array<string, mixed> */
    public static $definition = [
        'table' => 'snt_quote_line',
        'primary' => 'id_snt_quote_line',
        'fields' => [
            'id_snt_quote' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'line_type' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 10],
            'id_product' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'allow_null' => true],
            'id_product_attribute' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt', 'allow_null' => true],
            'label' => ['type' => self::TYPE_STRING, 'validate' => 'isCleanHtml', 'size' => 255, 'allow_null' => true],
            'reference' => ['type' => self::TYPE_STRING, 'validate' => 'isReference', 'size' => 64, 'allow_null' => true],
            'qty' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'catalog_price_te' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat', 'allow_null' => true],
            'unit_price_te' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'reduction_type' => ['type' => self::TYPE_STRING, 'size' => 8, 'allow_null' => true],
            'reduction_value' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat', 'allow_null' => true],
            'unit_net_te' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'total_te' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'id_tax_rules_group' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt', 'allow_null' => true],
            'tax_rate' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'ecotax' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'wholesale_price' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat', 'allow_null' => true],
            'comment' => ['type' => self::TYPE_STRING, 'validate' => 'isCleanHtml', 'allow_null' => true],
            'position' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
        ],
    ];

    public function getType(): LineType
    {
        return LineType::tryFrom((string) $this->line_type) ?? LineType::Product;
    }

    public function getReductionType(): ?ReductionType
    {
        return $this->reduction_type ? ReductionType::tryFrom((string) $this->reduction_type) : null;
    }
}
