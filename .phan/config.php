<?php

declare( strict_types = 1 );

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['exclude_file_list'][] = "{$VP}/vendor/marc-mabe/php-enum/stubs/Stringable.php";

return $cfg;
