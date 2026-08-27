<?php


$config = require __DIR__ . '/../config/config.php';

date_default_timezone_set($config['app']['timezone'] ?? 'America/Sao_Paulo');

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/meta_api.php';
require_once __DIR__ . '/attribution.php';
require_once __DIR__ . '/hotmart_sales_helper.php';
