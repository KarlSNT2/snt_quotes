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

// Les classes legacy sont déclarées « XxxCore » ; PrestaShop crée « Xxx » à l'exécution.
// On génère les déclarations équivalentes pour l'analyse.
$stubFile = __DIR__ . '/var/phpstan-legacy-aliases.php';
if (!is_file($stubFile) || filemtime($stubFile) < time() - 86400) {
    $declarations = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($psRoot . '/classes', FilesystemIterator::SKIP_DOTS));
    foreach ([$iterator, new RecursiveIteratorIterator(new RecursiveDirectoryIterator($psRoot . '/controllers', FilesystemIterator::SKIP_DOTS))] as $files) {
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match_all('/^\s*(abstract\s+|final\s+)?(class|interface)\s+(\w+)Core\b/m', $code, $m, PREG_SET_ORDER)) {
                foreach ($m as $match) {
                    $kind = $match[2] === 'interface' ? 'interface' : (trim($match[1]) === 'abstract' ? 'abstract class' : 'class');
                    $declarations[$match[3]] = $kind === 'interface'
                        ? "interface {$match[3]} extends {$match[3]}Core {}"
                        : "{$kind} {$match[3]} extends {$match[3]}Core {}";
                }
            }
        }
    }
    if (!is_dir(dirname($stubFile))) {
        mkdir(dirname($stubFile), 0777, true);
    }
    file_put_contents($stubFile, "<?php\n" . implode("\n", $declarations) . "\n");
}

return [
    'parameters' => [
        'scanFiles' => [$stubFile, $psRoot . '/config/alias.php'],
        // Modules de paiement utilisés (sources du module HiPay Payments, ex. dézippé) : HIPAY_DIR=/chemin/hipaypayments
        'scanDirectories' => array_values(array_filter([
            $psRoot . '/classes',
            $psRoot . '/controllers',
            getenv('HIPAY_DIR') ?: (is_dir($psRoot . '/modules/hipaypayments') ? $psRoot . '/modules/hipaypayments' : null),
        ])),
        'paths' => [
            __DIR__ . '/snt_quotes.php',
            __DIR__ . '/classes',
            __DIR__ . '/src',
        ],
        // Pas de config/autoload.php : l'index de classes du cœur dépend du cache de l'instance.
        // Les classes legacy sont découvertes via scanDirectories.
        'bootstrapFiles' => [
            $psRoot . '/config/defines.inc.php',
            __DIR__ . '/tests/phpstan-bootstrap.php',
            $psRoot . '/vendor/autoload.php',
        ],
        'dynamicConstantNames' => ['_PS_VERSION_', '_PS_MODE_DEV_'],
    ],
];
