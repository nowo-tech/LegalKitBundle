<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('Run composer install in LegalKitBundle before executing tests.');
}

require $autoload;

// Every run starts from fresh test kernel caches (no container compiled by an earlier run leaks into this one).
(new Filesystem())->remove(sys_get_temp_dir() . '/nowo-legal-kit-bundle');
