<?php

declare(strict_types=1);
// Compilation des gabarits Smarty du module avec le Smarty d'une instance PS (erreurs de syntaxe uniquement).
// Usage : php tools/smarty-lint.php <ps_root> <module_dir>
require $argv[1] . '/vendor/autoload.php';

$work = sys_get_temp_dir() . '/snt-smarty-lint-' . getmypid();
@mkdir($work . '/templates', 0777, true);
@mkdir($work . '/compile', 0777, true);
// Gabarit parent minimal pour {extends file='page.tpl'}.
file_put_contents($work . '/templates/page.tpl', '{block name=page_title}{/block}{block name=page_content}{/block}');

$smarty = new Smarty();
$smarty->setTemplateDir($work . '/templates');
$smarty->setCompileDir($work . '/compile');
$smarty->escape_html = true;
$noop = static fn (): string => '';
$smarty->registerPlugin('function', 'l', $noop);
$smarty->registerPlugin('modifier', 'intval', 'intval');

$errors = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[2] . '/views/templates', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->getExtension() !== 'tpl') {
        continue;
    }
    try {
        $smarty->createTemplate($file->getPathname())->compileTemplateSource();
        echo 'OK   ', $file->getFilename(), PHP_EOL;
    } catch (Throwable $e) {
        ++$errors;
        echo 'FAIL ', $file->getFilename(), ': ', $e->getMessage(), PHP_EOL;
    }
}
exit($errors ? 1 : 0);
