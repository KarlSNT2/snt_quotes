<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Journal d'un devis : changements de statut, e-mails, PDF archivés, notes. Jamais modifié après écriture.
 */
class SntQuoteHistory extends ObjectModel
{
    public const EVENT_STATUS = 'status_change';
    public const EVENT_CREATED = 'created';
    public const EVENT_UPDATED = 'updated';
    public const EVENT_EMAIL = 'email_sent';
    public const EVENT_PDF = 'pdf_archived';
    public const EVENT_NOTE = 'note';
    public const EVENT_PAYMENT_LINK = 'payment_link';

    public const ACTOR_EMPLOYEE = 'employee';
    public const ACTOR_CUSTOMER = 'customer';
    public const ACTOR_SYSTEM = 'system';

    /** @var int */
    public $id_snt_quote;
    /** @var string */
    public $event = self::EVENT_STATUS;
    /** @var string|null */
    public $status_from;
    /** @var string|null */
    public $status_to;
    /** @var string */
    public $actor_type = self::ACTOR_SYSTEM;
    /** @var int|null */
    public $actor_id;
    /** @var string|null */
    public $ip;
    /** @var string|null */
    public $comment;
    /** @var string|null JSON */
    public $payload;
    /** @var string */
    public $date_add;

    /** @var array<string, mixed> */
    public static $definition = [
        'table' => 'snt_quote_history',
        'primary' => 'id_snt_quote_history',
        'fields' => [
            'id_snt_quote' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'event' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 32],
            'status_from' => ['type' => self::TYPE_STRING, 'size' => 24, 'allow_null' => true],
            'status_to' => ['type' => self::TYPE_STRING, 'size' => 24, 'allow_null' => true],
            'actor_type' => ['type' => self::TYPE_STRING, 'size' => 10],
            'actor_id' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt', 'allow_null' => true],
            'ip' => ['type' => self::TYPE_STRING, 'size' => 45, 'allow_null' => true],
            'comment' => ['type' => self::TYPE_STRING, 'validate' => 'isCleanHtml', 'allow_null' => true],
            'payload' => ['type' => self::TYPE_STRING, 'allow_null' => true],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];
}
