<?php

declare(strict_types=1);

/*
 * Configuration PHPStan dynamique : si PS_ROOT_DIR pointe vers une instance PrestaShop,
 * on analyse tout le module avec l'autoload du cœur. Sinon on reste sur les couches pures.
 */

$psRoot = getenv('PS_ROOT_DIR') ?: '';
if ($psRoot === '' || !is_file($psRoot . '/config/defines.inc.php')) {
    return [];
}

return [
    'parameters' => [
        'paths' => [
            __DIR__ . '/snt_quotes.php',
            __DIR__ . '/classes',
            __DIR__ . '/src',
            __DIR__ . '/controllers',
        ],
        'bootstrapFiles' => [
            $psRoot . '/config/defines.inc.php',
            $psRoot . '/vendor/autoload.php',
            $psRoot . '/config/autoload.php',
        ],
        'scanDirectories' => [
            $psRoot . '/classes',
            $psRoot . '/controllers',
        ],
        'dynamicConstantNames' => ['_PS_VERSION_', '_PS_MODE_DEV_'],
    ],
];
