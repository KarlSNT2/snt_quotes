<?php

declare(strict_types=1);

namespace Snt\Quotes\Service;

use Snt\Quotes\Config\ConfigKeys;
use Snt\Quotes\Config\ModuleConfig;

/**
 * Numérotation sans trou ni doublon, par boutique et par année (compteur atomique en base).
 */
class ReferenceGenerator
{
    public function __construct(private readonly ModuleConfig $config)
    {
    }

    public function next(int $idShop, ?\DateTimeImmutable $date = null): string
    {
        $year = (int) ($date ?? new \DateTimeImmutable())->format('Y');
        $db = \Db::getInstance();
        $db->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'snt_quote_sequence` (`id_shop`, `year`, `last_number`)
             VALUES (' . $idShop . ', ' . $year . ', LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE `last_number` = LAST_INSERT_ID(`last_number` + 1)'
        );
        $number = (int) $db->getValue('SELECT LAST_INSERT_ID()', false);
        if ($number <= 0) {
            // Repli défensif (pilote MySQL ne renvoyant pas LAST_INSERT_ID(expr)).
            $number = (int) $db->getValue(
                'SELECT `last_number` FROM `' . _DB_PREFIX_ . 'snt_quote_sequence` WHERE `id_shop` = ' . $idShop . ' AND `year` = ' . $year,
                false
            );
        }

        return ReferenceFormatter::format(
            $this->config->get(ConfigKeys::REF_PATTERN, $idShop),
            $this->config->get(ConfigKeys::SHOP_CODE, $idShop),
            $year,
            $number
        );
    }
}
