<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/wp-stubs.php';

foreach (['config', 'validator', 'signature', 'client', 'package', 'updater'] as $class) {
    $file = dirname(__DIR__) . "/wawp-updater/src/class-$class.php";
    if (is_file($file)) {
        require_once $file;
    }
}
