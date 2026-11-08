<?php

class Schema
{
  public static function createTables(PDO $pdo)
  {
    $sql = array(
      "CREATE TABLE IF NOT EXISTS ps_product (
        id_product INT UNSIGNED NOT NULL PRIMARY KEY,
        reference VARCHAR(64) NOT NULL DEFAULT ''
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
      "CREATE TABLE IF NOT EXISTS ps_product_shop (
        id_product INT UNSIGNED NOT NULL,
        id_shop INT UNSIGNED NOT NULL,
        date_add DATETIME NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id_product, id_shop)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
      "CREATE TABLE IF NOT EXISTS ps_product_lang (
        id_product INT UNSIGNED NOT NULL,
        id_lang INT UNSIGNED NOT NULL,
        id_shop INT UNSIGNED NOT NULL,
        name VARCHAR(255) NOT NULL DEFAULT '',
        PRIMARY KEY (id_product, id_lang, id_shop)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
      "CREATE TABLE IF NOT EXISTS ps_orders (
        id_order INT UNSIGNED NOT NULL PRIMARY KEY,
        id_shop INT UNSIGNED NOT NULL,
        conversion_rate DECIMAL(13,6) NOT NULL DEFAULT 1,
        valid TINYINT(1) NOT NULL DEFAULT 0,
        invoice_date DATETIME NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
      "CREATE TABLE IF NOT EXISTS ps_order_detail (
        id_order_detail INT UNSIGNED NOT NULL PRIMARY KEY,
        id_order INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        product_quantity INT NOT NULL,
        unit_price_tax_excl DECIMAL(20,6) NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
      "CREATE TABLE IF NOT EXISTS ps_stock_available (
        id_product INT UNSIGNED NOT NULL,
        id_product_attribute INT UNSIGNED NOT NULL,
        id_shop INT UNSIGNED NOT NULL,
        quantity INT NOT NULL DEFAULT 0,
        PRIMARY KEY (id_product, id_product_attribute, id_shop)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
      "CREATE TABLE IF NOT EXISTS ps_page (
        id_page INT UNSIGNED NOT NULL PRIMARY KEY,
        id_object INT UNSIGNED NOT NULL,
        id_page_type INT UNSIGNED NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
      "CREATE TABLE IF NOT EXISTS ps_page_viewed (
        id_page INT UNSIGNED NOT NULL,
        id_date_range INT UNSIGNED NOT NULL,
        counter INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id_page, id_date_range)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
      "CREATE TABLE IF NOT EXISTS ps_date_range (
        id_date_range INT UNSIGNED NOT NULL PRIMARY KEY,
        time_start DATETIME NOT NULL,
        time_end DATETIME NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
    );

    foreach ($sql as $statement) {
      $pdo->exec($statement);
    }
  }

  public static function truncateAll(PDO $pdo)
  {
    $tables = array(
      'ps_page_viewed',
      'ps_page',
      'ps_date_range',
      'ps_order_detail',
      'ps_orders',
      'ps_stock_available',
      'ps_product_lang',
      'ps_product_shop',
      'ps_product',
    );

    foreach ($tables as $table) {
      $pdo->exec('DELETE FROM ' . $table);
    }
  }

  public static function applySessionSettings(PDO $pdo)
  {
    $pdo->exec("SET NAMES utf8");
    $pdo->exec("SET time_zone = '+00:00'");
    $pdo->exec("SET sql_mode = 'NO_ENGINE_SUBSTITUTION'");
    $pdo->exec("SET TIMESTAMP = UNIX_TIMESTAMP('2020-01-05 12:00:00')");
  }
}
