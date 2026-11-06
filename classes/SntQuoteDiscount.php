<?php

declare(strict_types=1);

use Snt\Quotes\Domain\ReductionType;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Remise sur le total produits : remise globale (scope=global) ou bon de réduction (scope=voucher).
 * Les remises de ligne sont portées par SntQuoteLine ; la remise sur le port par SntQuote.
 */
class SntQuoteDiscount extends ObjectModel
{
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_VOUCHER = 'voucher';

    /** @var int */
    public $id_snt_quote;
    /** @var string */
    public $scope = self::SCOPE_VOUCHER;
    /** @var int|null */
    public $id_cart_rule;
    /** @var bool bon dédié à créer à la conversion */
    public $is_dedicated = false;
    /** @var string|null */
    public $code;
    /** @var string */
    public $label = '';
    /** @var string */
    public $reduction_type = 'amount';
    /** @var float */
    public $reduction_value = 0.0;
    /** @var float */
    public $amount_te = 0.0;
    /** @var float */
    public $amount_ti = 0.0;
    /** @var string|null JSON : date de fin, minimum… pour un bon dédié */
    public $dedicated_params;

    /** @var array<string, mixed> */
    public static $definition = [
        'table' => 'snt_quote_discount',
        'primary' => 'id_snt_quote_discount',
        'fields' => [
            'id_snt_quote' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'scope' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 10],
            'id_cart_rule' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'allow_null' => true],
            'is_dedicated' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'code' => ['type' => self::TYPE_STRING, 'validate' => 'isCleanHtml', 'size' => 254, 'allow_null' => true],
            'label' => ['type' => self::TYPE_STRING, 'validate' => 'isCleanHtml', 'size' => 255, 'required' => true],
            'reduction_type' => ['type' => self::TYPE_STRING, 'size' => 8],
            'reduction_value' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'amount_te' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'amount_ti' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'dedicated_params' => ['type' => self::TYPE_STRING, 'allow_null' => true],
        ],
    ];

    public function getReductionType(): ReductionType
    {
        return ReductionType::tryFrom((string) $this->reduction_type) ?? ReductionType::Amount;
    }
}
