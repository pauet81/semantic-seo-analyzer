<?php
$default = require __DIR__ . '/config.default.php';
$localPath = __DIR__ . '/config.local.php';
$overrides = file_exists($localPath) ? require $localPath : [];
return array_replace_recursive($default, $overrides);
