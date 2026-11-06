<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Archive d'un PDF envoyé au client (fichier figé + empreinte SHA-256).
 */
class SntQuotePdf extends ObjectModel
{
    /** @var int */
    public $id_snt_quote;
    /** @var int */
    public $version = 1;
    /** @var string s3|local */
    public $storage = 's3';
    /** @var string clé objet (S3) ou chemin relatif (local) */
    public $file_path = '';
    /** @var string */
    public $sha256 = '';
    /** @var string */
    public $date_add;

    /** @var array<string, mixed> */
    public static $definition = [
        'table' => 'snt_quote_pdf',
        'primary' => 'id_snt_quote_pdf',
        'fields' => [
            'id_snt_quote' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'version' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'storage' => ['type' => self::TYPE_STRING, 'size' => 8],
            'file_path' => ['type' => self::TYPE_STRING, 'size' => 255, 'required' => true],
            'sha256' => ['type' => self::TYPE_STRING, 'size' => 64, 'required' => true],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];
}
