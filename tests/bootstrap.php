<?php

error_reporting(-1);
ini_set('display_errors', '1');

if (!defined('_PS_VERSION_')) {
  define('_PS_VERSION_', '1.7.6.9');
}
if (!defined('_DB_PREFIX_')) {
  define('_DB_PREFIX_', 'ps_');
}
if (!defined('_PS_USE_SQL_SLAVE_')) {
  define('_PS_USE_SQL_SLAVE_', false);
}

$root = dirname(__DIR__);
require $root . '/tests/support/Schema.php';
require $root . '/tests/support/PrestaShopDouble.php';

$pdo = StatsTestCaseHelper::connect();
PsDb::setPdo($pdo);

require $root . '/statsbestproducts.php';
require $root . '/tests/support/StatsBestProductsFixture.php';
require $root . '/upgrade/upgrade-2.0.1.php';
