<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Lien de paiement / consultation d'un devis. Seule l'empreinte SHA-256 du jeton est stockée.
 */
class SntQuotePaymentLink extends ObjectModel
{
    /** @var int */
    public $id_snt_quote;
    /** @var string */
    public $token_hash = '';
    /** @var string pay|view */
    public $purpose = 'pay';
    /** @var string|null JSON */
    public $methods;
    /** @var string */
    public $expires_at;
    /** @var string|null */
    public $used_at;
    /** @var string|null */
    public $revoked_at;
    /** @var string|null */
    public $gateway_reference;
    /** @var string */
    public $date_add;

    /** @var array<string, mixed> */
    public static $definition = [
        'table' => 'snt_quote_payment_link',
        'primary' => 'id_snt_quote_payment_link',
        'fields' => [
            'id_snt_quote' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'token_hash' => ['type' => self::TYPE_STRING, 'size' => 64, 'required' => true],
            'purpose' => ['type' => self::TYPE_STRING, 'size' => 8],
            'methods' => ['type' => self::TYPE_STRING, 'allow_null' => true],
            'expires_at' => ['type' => self::TYPE_DATE, 'validate' => 'isDate', 'required' => true],
            'used_at' => ['type' => self::TYPE_DATE, 'validate' => 'isDate', 'allow_null' => true],
            'revoked_at' => ['type' => self::TYPE_DATE, 'validate' => 'isDate', 'allow_null' => true],
            'gateway_reference' => ['type' => self::TYPE_STRING, 'size' => 64, 'allow_null' => true],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    public function isExpired(): bool
    {
        return strtotime((string) $this->expires_at) < time();
    }

    public function isUsable(): bool
    {
        return !$this->used_at && !$this->revoked_at && !$this->isExpired();
    }

    /**
     * @return string[]
     */
    public function getMethods(): array
    {
        $decoded = json_decode((string) $this->methods, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }
}
