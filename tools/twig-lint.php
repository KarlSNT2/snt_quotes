<?php

declare(strict_types=1);
// Analyse syntaxique des gabarits Twig du module avec le Twig d’une instance PS. Usage : php tools/twig-lint.php <ps_root> <module_dir>
require $argv[1] . '/vendor/autoload.php';

use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\TwigFunction;

$twig = new Environment(new ArrayLoader([]), ['strict_variables' => false]);
$twig->addExtension(new TranslationExtension());
foreach (['path', 'asset', 'form_start', 'form_end', 'form_row', 'form_rest', 'form_widget', 'form_label', 'form_errors'] as $fn) {
    $twig->addFunction(new TwigFunction($fn, static fn () => '', ['is_safe' => ['html']]));
}
$errors = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[2] . '/views', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!str_ends_with($file->getFilename(), '.twig')) {
        continue;
    }
    try {
        $twig->parse($twig->tokenize(new Source(file_get_contents($file->getPathname()), $file->getFilename())));
        echo 'OK   ', $file->getFilename(), PHP_EOL;
    } catch (Throwable $e) {
        ++$errors;
        echo 'FAIL ', $file->getFilename(), ': ', $e->getMessage(), PHP_EOL;
    }
}
exit($errors ? 1 : 0);
