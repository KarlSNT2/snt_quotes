<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Fil d'échanges client ↔ équipe sur un devis.
 */
class SntQuoteMessage extends ObjectModel
{
    /** @var int */
    public $id_snt_quote;
    /** @var string employee|customer */
    public $author_type;
    /** @var int */
    public $author_id = 0;
    /** @var string */
    public $message = '';
    /** @var string|null */
    public $attachment;
    /** @var string|null */
    public $attachment_name;
    /** @var string|null */
    public $read_at;
    /** @var string */
    public $date_add;

    /** @var array<string, mixed> */
    public static $definition = [
        'table' => 'snt_quote_message',
        'primary' => 'id_snt_quote_message',
        'fields' => [
            'id_snt_quote' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'author_type' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 10, 'required' => true],
            'author_id' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'message' => ['type' => self::TYPE_STRING, 'validate' => 'isCleanHtml', 'required' => true],
            'attachment' => ['type' => self::TYPE_STRING, 'size' => 255, 'allow_null' => true],
            'attachment_name' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 255, 'allow_null' => true],
            'read_at' => ['type' => self::TYPE_DATE, 'validate' => 'isDate', 'allow_null' => true],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];
}
