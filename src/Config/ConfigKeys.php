<?php

declare(strict_types=1);

namespace Snt\Quotes\Config;

/**
 * Clés de configuration (table ps_configuration), toutes par boutique.
 */
final class ConfigKeys
{
    // Général
    public const VALIDITY_DAYS = 'SNT_QUOTES_VALIDITY_DAYS';
    public const REMINDER_DAYS = 'SNT_QUOTES_REMINDER_DAYS';
    public const SHOP_CODE = 'SNT_QUOTES_SHOP_CODE';
    public const REF_PATTERN = 'SNT_QUOTES_REF_PATTERN';
    public const STOCK_RESERVATION = 'SNT_QUOTES_STOCK_RESERVATION';
    public const RESPONSE_DELAY_DAYS = 'SNT_QUOTES_RESPONSE_DELAY_DAYS';
    public const FO_REQUEST_ENABLED = 'SNT_QUOTES_FO_REQUEST_ENABLED';
    public const FO_PRODUCT_BUTTON = 'SNT_QUOTES_FO_PRODUCT_BUTTON';

    // Paiement
    public const PAYMENT_GATEWAY = 'SNT_QUOTES_PAYMENT_GATEWAY';
    public const METHODS_DEFAULT = 'SNT_QUOTES_METHODS_DEFAULT';
    public const PAYMENT_TERMS = 'SNT_QUOTES_PAYMENT_TERMS';
    public const IBAN = 'SNT_QUOTES_IBAN';
    public const BIC = 'SNT_QUOTES_BIC';
    public const PAYLINK_TTL_DAYS = 'SNT_QUOTES_PAYLINK_TTL_DAYS';

    // PDF / e-mails
    public const PDF_COLOR = 'SNT_QUOTES_PDF_COLOR';
    public const PDF_LEGAL = 'SNT_QUOTES_PDF_LEGAL';
    public const PDF_CGV_SOURCE = 'SNT_QUOTES_PDF_CGV_SOURCE';
    public const PDF_CGV_TEXT = 'SNT_QUOTES_PDF_CGV_TEXT';
    public const PDF_CGV_ID_CMS = 'SNT_QUOTES_PDF_CGV_ID_CMS';
    public const MAIL_FROM = 'SNT_QUOTES_MAIL_FROM';
    public const MAIL_CC_EMPLOYEE = 'SNT_QUOTES_MAIL_CC_EMPLOYEE';
    public const MAIL_DISABLED = 'SNT_QUOTES_MAIL_DISABLED';

    // Stockage PDF (Scaleway Object Storage)
    public const S3_ENDPOINT = 'SNT_QUOTES_S3_ENDPOINT';
    public const S3_REGION = 'SNT_QUOTES_S3_REGION';
    public const S3_BUCKET = 'SNT_QUOTES_S3_BUCKET';
    public const S3_KEY = 'SNT_QUOTES_S3_KEY';
    public const S3_SECRET = 'SNT_QUOTES_S3_SECRET';

    // Droits
    public const DISCOUNT_THRESHOLD = 'SNT_QUOTES_DISCOUNT_THRESHOLD';
    public const PROFILES_APPROVE = 'SNT_QUOTES_PROFILES_APPROVE';
    public const PROFILES_CONVERT = 'SNT_QUOTES_PROFILES_CONVERT';
    public const PROFILES_SEE_MARGIN = 'SNT_QUOTES_PROFILES_SEE_MARGIN';

    // Tâche planifiée (relances, expirations)
    public const CRON_TOKEN = 'SNT_QUOTES_CRON_TOKEN';
    /** Date du dernier passage réussi (affichée dans la configuration). */
    public const CRON_LAST_RUN = 'SNT_QUOTES_CRON_LAST_RUN';

    /** Affichage des prix côté client : 'ti' (TTC d'abord), 'te' (HT d'abord), 'group' (selon le groupe du client). */
    public const PRICE_DISPLAY = 'SNT_QUOTES_PRICE_DISPLAY';

    /** Clés retirées (webhooks n8n abandonnés en 0.3.0) : supprimées à la mise à jour. */
    public const OBSOLETE = ['SNT_QUOTES_WEBHOOK_URL', 'SNT_QUOTES_WEBHOOK_SECRET'];

    // Statuts de commande (globaux, modifiables dans l'onglet Paiement)
    public const OS_PENDING = 'SNT_QUOTES_OS_PENDING';
    public const OS_DEFERRED = 'SNT_QUOTES_OS_DEFERRED';
    public const OS_TRANSFER = 'SNT_QUOTES_OS_TRANSFER';
    public const OS_ERROR = 'SNT_QUOTES_OS_ERROR';

    // Technique (non éditable)
    public const CARRIER_ID_REFERENCE = 'SNT_QUOTES_CARRIER_ID_REFERENCE';
    public const FREE_LINE_PRODUCTS = 'SNT_QUOTES_FREE_LINE_PRODUCTS';

    /**
     * Valeurs par défaut posées à l'installation (et à chaque nouvelle boutique).
     *
     * @return array<string, string|int>
     */
    public static function defaults(): array
    {
        return [
            self::VALIDITY_DAYS => 30,
            self::REMINDER_DAYS => 7,
            self::SHOP_CODE => '',
            self::REF_PATTERN => 'DEV-{SHOP}-{YEAR}-{NUM}',
            self::STOCK_RESERVATION => 0,
            self::RESPONSE_DELAY_DAYS => 2,
            self::FO_REQUEST_ENABLED => 1,
            self::FO_PRODUCT_BUTTON => 0,
            self::PAYMENT_GATEWAY => 'hipaypayments',
            self::METHODS_DEFAULT => 'card,transfer',
            self::PAYMENT_TERMS => '',
            self::IBAN => '',
            self::BIC => '',
            self::PAYLINK_TTL_DAYS => 15,
            self::PDF_COLOR => '#1F3A5F',
            self::PDF_LEGAL => '',
            self::PDF_CGV_SOURCE => 'none',
            self::PDF_CGV_TEXT => '',
            self::PDF_CGV_ID_CMS => 0,
            self::MAIL_FROM => 'shop',
            self::MAIL_CC_EMPLOYEE => 1,
            self::MAIL_DISABLED => '',
            self::S3_ENDPOINT => 'https://s3.fr-par.scw.cloud',
            self::S3_REGION => 'fr-par',
            self::S3_BUCKET => '',
            self::S3_KEY => '',
            self::S3_SECRET => '',
            self::DISCOUNT_THRESHOLD => 15,
            self::PROFILES_APPROVE => '1',
            self::PROFILES_CONVERT => '1',
            self::PROFILES_SEE_MARGIN => '1',
            self::PRICE_DISPLAY => 'ti',
        ];
    }

    /**
     * Toutes les clés à supprimer à la désinstallation.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return array_merge(array_keys(self::defaults()), self::OBSOLETE, [
            self::CRON_TOKEN,
            self::CRON_LAST_RUN,
            self::CARRIER_ID_REFERENCE,
            self::FREE_LINE_PRODUCTS,
            self::OS_PENDING,
            self::OS_DEFERRED,
            self::OS_TRANSFER,
            self::OS_ERROR,
        ]);
    }
}
