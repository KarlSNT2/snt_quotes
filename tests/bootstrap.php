<?php

declare(strict_types=1);

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

spl_autoload_register(static function (string $class): void {
    foreach (['Snt\\Quotes\\Tests\\' => __DIR__ . '/', 'Snt\\Quotes\\' => __DIR__ . '/../src/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }

            return;
        }
    }
});
