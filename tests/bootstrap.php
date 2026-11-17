<?php

// Prefer the Composer autoloader when dependencies are installed,
// otherwise fall back to a minimal PSR-0 autoloader for the src/ tree
// so the test suite can run without a `composer install` step.

$composerAutoload = __DIR__.'/../vendor/autoload.php';

if (file_exists($composerAutoload)) {
    require $composerAutoload;
} else {
    spl_autoload_register(function ($class) {
        $prefix = 'Captioning\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = __DIR__.'/../src/Captioning/'.$relative.'.php';
        if (file_exists($file)) {
            require $file;
        }
    });
}
