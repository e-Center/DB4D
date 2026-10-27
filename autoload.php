<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'DB4D\\') && preg_match('/^DB4D\\\\[A-Za-z][A-Za-z0-9_]*$/D', $class)) {
        $file = __DIR__ . '/src/DB4D/' . substr($class, 5) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
