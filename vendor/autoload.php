<?php
require_once __DIR__ . '/composer/ClassLoader.php';
$psr4Map = require __DIR__ . '/composer/autoload_psr4.php';
BlueprintVendorAutoloader::register($psr4Map);
$files = require __DIR__ . '/composer/autoload_files.php';
foreach ($files as $file) {
    require_once $file;
}
