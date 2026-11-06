<?php

declare(strict_types=1);

/*
 * Constantes PrestaShop normalement définies au runtime (config/settings, init),
 * nécessaires à l'analyse statique sans base de données.
 */
foreach ([
    '_PS_VERSION_' => '8.2.0',
    '_DB_PREFIX_' => 'ps_',
    '_MYSQL_ENGINE_' => 'InnoDB',
    '_PS_USE_SQL_SLAVE_' => false,
    '_COOKIE_KEY_' => 'phpstan',
    '_THEME_SHIP_DIR_' => '/img/s/',
    '__PS_BASE_URI__' => '/',
    '_PS_IMG_' => '/img/',
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}
