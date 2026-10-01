<?php

declare(strict_types=1);

$catalog = require base_path('Modules/Catalog/config/config.php');
$catalog['connection'] ??= 'catalog';
$catalog['storage_root'] ??= storage_path('app/catalog');

// Resolve the configurable compiler environment key while building the config cache.
$binaryEnvironment = (string) $catalog['settings']['latex']['pdflatex_env'];
$catalog['settings']['latex']['default_binary'] = env(
    $binaryEnvironment,
    $catalog['settings']['latex']['default_binary'],
);

return $catalog;
