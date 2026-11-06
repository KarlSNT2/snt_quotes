<?php

declare(strict_types=1);

use Snt\Quotes\Domain\QuoteStatus;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * En-tête de devis. Montants figés HT/TTC ; recalculés uniquement par QuoteEditor.
 */
class SntQuote extends ObjectModel
{
    /** @var string */
    public $reference = '';
    /** @var int */
    public $version = 1;
    /** @var int|null */
    public $id_parent_quote;
    /** @var int|null */
    public $id_root_quote;
    /** @var int */
    public $id_shop;
    /** @var int */
    public $id_shop_group = 1;
    /** @var int */
    public $id_lang;
    /** @var int */
    public $id_currency;
    /** @var float */
    public $conversion_rate = 1.0;
    /** @var int */
    public $id_customer = 0;
    /** @var int|null */
    public $id_employee;
    /** @var int|null */
    public $id_address_delivery;
    /** @var int|null */
    public $id_address_invoice;
    /** @var string|null */
    public $project_name;
    /** @var int|null */
    public $id_carrier_real;
    /** @var int|null */
    public $id_carrier_reference;
    /** @var string|null */
    public $carrier_label;
    /** @var string */
    public $delivery_mode = 'carrier';
    /** @var string|null JSON */
    public $relay_data;
    /** @var float */
    public $shipping_tax_excl = 0.0;
    /** @var float */
    public $shipping_discount_te = 0.0;
    /** @var bool */
    public $free_shipping = false;
    /** @var int|null */
    public $id_tax_rules_group_shipping;
    /** @var float */
    public $shipping_tax_rate = 0.0;
    /** @var string|null */
    public $global_discount_type;
    /** @var float|null */
    public $global_discount_value;
    /** @var float */
    public $total_products_te = 0.0;
    /** @var float */
    public $total_discount_te = 0.0;
    /** @var float */
    public $total_shipping_te = 0.0;
    /** @var float */
    public $total_te = 0.0;
    /** @var float */
    public $total_tax = 0.0;
    /** @var float */
    public $total_ti = 0.0;
    /** @var string|null JSON */
    public $tax_breakdown;
    /** @var string|null */
    public $vat_number;
    /** @var bool */
    public $tax_exempt = false;
    /** @var string */
    public $status = 'draft';
    /** @var string|null Y-m-d */
    public $valid_until;
    /** @var string|null */
    public $payment_mode;
    /** @var string|null JSON */
    public $allowed_payment_methods;
    /** @var string|null */
    public $customer_message;
    /** @var string|null */
    public $internal_note;
    /** @var int|null */
    public $id_cart;
    /** @var int|null */
    public $id_cart_source;
    /** @var int|null */
    public $id_order_source;
    /** @var int|null */
    public $id_order;
    /** @var string|null */
    public $sent_at;
    /** @var string|null */
    public $viewed_at;
    /** @var string|null */
    public $accepted_at;
    /** @var string|null */
    public $accepted_ip;
    /** @var string|null */
    public $accepted_cgv_version;
    /** @var string|null */
    public $last_reminder_at;
    /** @var string */
    public $source = 'bo';
    /** @var bool */
    public $deleted = false;
    /** @var string */
    public $date_add;
    /** @var string */
    public $date_upd;

    /** @var array<string, mixed> */
    public static $definition = [
        'table' => 'snt_quote',
        'primary' => 'id_snt_quote',
        'fields' => [
            'reference' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 32],
            'version' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'id_parent_quote' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'allow_null' => true],
            'id_root_quote' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'allow_null' => true],
            'id_shop' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_shop_group' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId'],
            'id_lang' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_currency' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'conversion_rate' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'id_customer' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'id_employee' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'allow_null' => true],
            'id_address_delivery' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'allow_null' => true],
            'id_address_invoice' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'allow_null' => true],
            'project_name' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 128, 'allow_null' => true],
            'id_carrier_real' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'allow_null' => true],
            'id_carrier_reference' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'allow_null' => true],
            'carrier_label' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 128, 'allow_null' => true],
            'delivery_mode' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 16],
            'relay_data' => ['type' => self::TYPE_STRING, 'allow_null' => true],
            'shipping_tax_excl' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'shipping_discount_te' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'free_shipping' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'id_tax_rules_group_shipping' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt', 'allow_null' => true],
            'shipping_tax_rate' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'global_discount_type' => ['type' => self::TYPE_STRING, 'size' => 8, 'allow_null' => true],
            'global_discount_value' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat', 'allow_null' => true],
            'total_products_te' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'total_discount_te' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'total_shipping_te' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'total_te' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'total_tax' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'total_ti' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'tax_breakdown' => ['type' => self::TYPE_STRING, 'allow_null' => true],
            'vat_number' => ['type' => self::TYPE_STRING, 'size' => 32, 'allow_null' => true],
            'tax_exempt' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'status' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 24],
            'valid_until' => ['type' => self::TYPE_DATE, 'validate' => 'isDateFormat', 'allow_null' => true],
            'payment_mode' => ['type' => self::TYPE_STRING, 'size' => 32, 'allow_null' => true],
            'allowed_payment_methods' => ['type' => self::TYPE_STRING, 'allow_null' => true],
            'customer_message' => ['type' => self::TYPE_STRING, 'validate' => 'isCleanHtml', 'allow_null' => true],
            'internal_note' => ['type' => self::TYPE_STRING, 'validate' => 'isCleanHtml', 'allow_null' => true],
            'id_cart' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'allow_null' => true],
            'id_cart_source' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'allow_null' => true],
            'id_order_source' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'allow_null' => true],
            'id_order' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'allow_null' => true],
            'sent_at' => ['type' => self::TYPE_DATE, 'validate' => 'isDate', 'allow_null' => true],
            'viewed_at' => ['type' => self::TYPE_DATE, 'validate' => 'isDate', 'allow_null' => true],
            'accepted_at' => ['type' => self::TYPE_DATE, 'validate' => 'isDate', 'allow_null' => true],
            'accepted_ip' => ['type' => self::TYPE_STRING, 'size' => 45, 'allow_null' => true],
            'accepted_cgv_version' => ['type' => self::TYPE_STRING, 'size' => 32, 'allow_null' => true],
            'last_reminder_at' => ['type' => self::TYPE_DATE, 'validate' => 'isDate', 'allow_null' => true],
            'source' => ['type' => self::TYPE_STRING, 'size' => 16],
            'deleted' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    public function getStatus(): QuoteStatus
    {
        return QuoteStatus::tryFrom((string) $this->status) ?? QuoteStatus::Draft;
    }

    public function isEditable(): bool
    {
        return $this->getStatus()->isEditable() && !$this->id_order;
    }

    /**
     * Libellé complet « DEV-ABOT-2026-00042 v2 ».
     */
    public function getFullReference(): string
    {
        return $this->reference . ((int) $this->version > 1 ? ' v' . (int) $this->version : '');
    }

    public function isExpiredByDate(?DateTimeImmutable $now = null): bool
    {
        if (!$this->valid_until) {
            return false;
        }
        $now ??= new DateTimeImmutable('today');

        return new DateTimeImmutable((string) $this->valid_until) < $now;
    }

    /**
     * @return SntQuoteLine[]
     */
    public function getLines(): array
    {
        if (!$this->id) {
            return [];
        }
        $rows = Db::getInstance()->executeS(
            'SELECT `id_snt_quote_line` FROM `' . _DB_PREFIX_ . 'snt_quote_line`
             WHERE `id_snt_quote` = ' . (int) $this->id . ' ORDER BY `position` ASC, `id_snt_quote_line` ASC'
        ) ?: [];

        return array_map(static fn (array $r): SntQuoteLine => new SntQuoteLine((int) $r['id_snt_quote_line']), $rows);
    }

    /**
     * @return SntQuoteDiscount[]
     */
    public function getDiscounts(): array
    {
        if (!$this->id) {
            return [];
        }
        $rows = Db::getInstance()->executeS(
            'SELECT `id_snt_quote_discount` FROM `' . _DB_PREFIX_ . 'snt_quote_discount`
             WHERE `id_snt_quote` = ' . (int) $this->id . ' ORDER BY `id_snt_quote_discount` ASC'
        ) ?: [];

        return array_map(static fn (array $r): SntQuoteDiscount => new SntQuoteDiscount((int) $r['id_snt_quote_discount']), $rows);
    }

    /**
     * @return string[]
     */
    public function getAllowedPaymentMethods(): array
    {
        $decoded = json_decode((string) $this->allowed_payment_methods, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /**
     * @return array<int, array{rate: float, base: float, tax: float}>
     */
    public function getTaxBreakdown(): array
    {
        $decoded = json_decode((string) $this->tax_breakdown, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRelayData(): ?array
    {
        $decoded = json_decode((string) $this->relay_data, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Suppression : lignes, remises, messages, historique et liens sont supprimés avec l'en-tête.
     */
    public function delete(): bool
    {
        $id = (int) $this->id;
        $ok = parent::delete();
        if ($ok && $id) {
            foreach (['snt_quote_line', 'snt_quote_discount', 'snt_quote_history', 'snt_quote_message', 'snt_quote_payment_link', 'snt_quote_pdf'] as $table) {
                Db::getInstance()->delete($table, '`id_snt_quote` = ' . $id);
            }
        }

        return $ok;
    }
}
