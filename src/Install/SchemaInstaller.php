<?php

declare(strict_types=1);

namespace Snt\Quotes\Install;

/**
 * Création / suppression des tables du module.
 * Les colonnes JSON sont en TEXT (compatibilité MySQL 5.7 / MariaDB).
 */
final class SchemaInstaller
{
    /**
     * @return array<string, string> table => DDL (sans préfixe)
     */
    public static function tables(): array
    {
        $engine = 'ENGINE=' . (defined('_MYSQL_ENGINE_') ? _MYSQL_ENGINE_ : 'InnoDB') . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        return [
            'snt_quote' => "(
                `id_snt_quote` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `reference` VARCHAR(32) NOT NULL,
                `version` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                `id_parent_quote` INT UNSIGNED NULL,
                `id_root_quote` INT UNSIGNED NULL,
                `id_shop` INT UNSIGNED NOT NULL,
                `id_shop_group` INT UNSIGNED NOT NULL DEFAULT 1,
                `id_lang` INT UNSIGNED NOT NULL,
                `id_currency` INT UNSIGNED NOT NULL,
                `conversion_rate` DECIMAL(13,6) NOT NULL DEFAULT 1,
                `id_customer` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_employee` INT UNSIGNED NULL,
                `id_address_delivery` INT UNSIGNED NULL,
                `id_address_invoice` INT UNSIGNED NULL,
                `project_name` VARCHAR(128) NULL,
                `id_carrier_real` INT UNSIGNED NULL,
                `id_carrier_reference` INT UNSIGNED NULL,
                `carrier_label` VARCHAR(128) NULL,
                `delivery_mode` VARCHAR(16) NOT NULL DEFAULT 'carrier',
                `relay_data` TEXT NULL,
                `shipping_tax_excl` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `shipping_discount_te` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `free_shipping` TINYINT(1) NOT NULL DEFAULT 0,
                `id_tax_rules_group_shipping` INT UNSIGNED NULL,
                `shipping_tax_rate` DECIMAL(10,3) NOT NULL DEFAULT 0,
                `global_discount_type` VARCHAR(8) NULL,
                `global_discount_value` DECIMAL(20,6) NULL,
                `total_products_te` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `total_discount_te` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `total_shipping_te` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `total_te` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `total_tax` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `total_ti` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `tax_breakdown` TEXT NULL,
                `vat_number` VARCHAR(32) NULL,
                `tax_exempt` TINYINT(1) NOT NULL DEFAULT 0,
                `status` VARCHAR(24) NOT NULL DEFAULT 'draft',
                `valid_until` DATE NULL,
                `payment_mode` VARCHAR(32) NULL,
                `allowed_payment_methods` TEXT NULL,
                `customer_message` TEXT NULL,
                `internal_note` TEXT NULL,
                `id_cart` INT UNSIGNED NULL,
                `id_cart_source` INT UNSIGNED NULL,
                `id_order_source` INT UNSIGNED NULL,
                `id_order` INT UNSIGNED NULL,
                `sent_at` DATETIME NULL,
                `viewed_at` DATETIME NULL,
                `accepted_at` DATETIME NULL,
                `accepted_ip` VARCHAR(45) NULL,
                `accepted_cgv_version` VARCHAR(32) NULL,
                `last_reminder_at` DATETIME NULL,
                `source` VARCHAR(16) NOT NULL DEFAULT 'bo',
                `deleted` TINYINT(1) NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_snt_quote`),
                UNIQUE KEY `uk_reference_version` (`id_shop`, `reference`, `version`),
                UNIQUE KEY `uk_id_order` (`id_order`),
                KEY `k_customer` (`id_customer`),
                KEY `k_status` (`id_shop`, `status`),
                KEY `k_valid` (`valid_until`),
                KEY `k_cart` (`id_cart`)
            ) $engine",

            'snt_quote_line' => "(
                `id_snt_quote_line` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_snt_quote` INT UNSIGNED NOT NULL,
                `line_type` VARCHAR(10) NOT NULL DEFAULT 'product',
                `id_product` INT UNSIGNED NULL,
                `id_product_attribute` INT UNSIGNED NULL,
                `label` VARCHAR(255) NULL,
                `reference` VARCHAR(64) NULL,
                `qty` INT UNSIGNED NOT NULL DEFAULT 0,
                `catalog_price_te` DECIMAL(20,6) NULL,
                `unit_price_te` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `reduction_type` VARCHAR(8) NULL,
                `reduction_value` DECIMAL(20,6) NULL,
                `unit_net_te` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `total_te` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `id_tax_rules_group` INT UNSIGNED NULL,
                `tax_rate` DECIMAL(10,3) NOT NULL DEFAULT 0,
                `ecotax` DECIMAL(17,6) NOT NULL DEFAULT 0,
                `wholesale_price` DECIMAL(20,6) NULL,
                `comment` TEXT NULL,
                `position` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id_snt_quote_line`),
                KEY `k_quote` (`id_snt_quote`, `position`)
            ) $engine",

            'snt_quote_discount' => "(
                `id_snt_quote_discount` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_snt_quote` INT UNSIGNED NOT NULL,
                `scope` VARCHAR(10) NOT NULL DEFAULT 'voucher',
                `id_cart_rule` INT UNSIGNED NULL,
                `is_dedicated` TINYINT(1) NOT NULL DEFAULT 0,
                `code` VARCHAR(254) NULL,
                `label` VARCHAR(255) NOT NULL,
                `reduction_type` VARCHAR(8) NOT NULL DEFAULT 'amount',
                `reduction_value` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `amount_te` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `amount_ti` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `dedicated_params` TEXT NULL,
                PRIMARY KEY (`id_snt_quote_discount`),
                KEY `k_quote` (`id_snt_quote`)
            ) $engine",

            'snt_quote_history' => "(
                `id_snt_quote_history` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_snt_quote` INT UNSIGNED NOT NULL,
                `event` VARCHAR(32) NOT NULL,
                `status_from` VARCHAR(24) NULL,
                `status_to` VARCHAR(24) NULL,
                `actor_type` VARCHAR(10) NOT NULL DEFAULT 'system',
                `actor_id` INT UNSIGNED NULL,
                `ip` VARCHAR(45) NULL,
                `comment` TEXT NULL,
                `payload` TEXT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_snt_quote_history`),
                KEY `k_quote` (`id_snt_quote`, `date_add`)
            ) $engine",

            'snt_quote_message' => "(
                `id_snt_quote_message` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_snt_quote` INT UNSIGNED NOT NULL,
                `author_type` VARCHAR(10) NOT NULL,
                `author_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `message` TEXT NOT NULL,
                `attachment` VARCHAR(255) NULL,
                `attachment_name` VARCHAR(255) NULL,
                `read_at` DATETIME NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_snt_quote_message`),
                KEY `k_quote` (`id_snt_quote`)
            ) $engine",

            'snt_quote_payment_link' => "(
                `id_snt_quote_payment_link` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_snt_quote` INT UNSIGNED NOT NULL,
                `token_hash` CHAR(64) NOT NULL,
                `purpose` VARCHAR(8) NOT NULL DEFAULT 'pay',
                `methods` TEXT NULL,
                `expires_at` DATETIME NOT NULL,
                `used_at` DATETIME NULL,
                `revoked_at` DATETIME NULL,
                `gateway_reference` VARCHAR(64) NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_snt_quote_payment_link`),
                UNIQUE KEY `uk_token` (`token_hash`),
                KEY `k_quote` (`id_snt_quote`)
            ) $engine",

            'snt_quote_pdf' => "(
                `id_snt_quote_pdf` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_snt_quote` INT UNSIGNED NOT NULL,
                `version` SMALLINT UNSIGNED NOT NULL,
                `storage` VARCHAR(8) NOT NULL DEFAULT 's3',
                `file_path` VARCHAR(255) NOT NULL,
                `sha256` CHAR(64) NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_snt_quote_pdf`),
                KEY `k_quote` (`id_snt_quote`)
            ) $engine",

            'snt_quote_sequence' => "(
                `id_shop` INT UNSIGNED NOT NULL,
                `year` SMALLINT UNSIGNED NOT NULL,
                `last_number` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id_shop`, `year`)
            ) $engine",

            'snt_quote_webhook_log' => "(
                `id_snt_quote_webhook_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL,
                `id_snt_quote` INT UNSIGNED NULL,
                `event` VARCHAR(32) NOT NULL,
                `payload` MEDIUMTEXT NOT NULL,
                `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `http_code` SMALLINT NULL,
                `response` TEXT NULL,
                `status` VARCHAR(8) NOT NULL DEFAULT 'pending',
                `next_retry_at` DATETIME NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_snt_quote_webhook_log`),
                KEY `k_status` (`status`, `next_retry_at`)
            ) $engine",
        ];
    }

    public function install(): bool
    {
        $db = \Db::getInstance();
        foreach (self::tables() as $table => $ddl) {
            if (!$db->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . $table . '` ' . $ddl)) {
                return false;
            }
        }

        return true;
    }

    public function uninstall(): bool
    {
        $db = \Db::getInstance();
        foreach (array_keys(self::tables()) as $table) {
            $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`');
        }

        return true;
    }
}
