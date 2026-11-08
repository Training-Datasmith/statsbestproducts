<?php

class GetDataTest extends PHPUnit_Framework_TestCase
{
  protected function setUp()
  {
    StatsTestCaseHelper::resetEnvironment();
  }

  public function testAggregateSalesStockAndPageViewsForTheFixedWindow()
  {
    $pdo = PsDb::pdo();
    $this->insertProduct($pdo, 1, 'MUG-1', '2019-01-01 00:00:00', 1);
    $pdo->exec("INSERT INTO ps_product_lang (id_product, id_lang, id_shop, name) VALUES (1, 1, 1, 'Mug'), (1, 2, 1, 'Tasse'), (1, 1, 2, 'Wrong shop')");
    $pdo->exec('INSERT INTO ps_stock_available (id_product, id_product_attribute, id_shop, quantity) VALUES (1, 0, 1, 7), (1, 0, 2, 99), (1, 5, 1, 50)');
    $this->insertOrderLine($pdo, 1, 1, 1, 1, 1, 2, '2020-01-01 00:00:00', 10);
    $this->insertOrderLine($pdo, 2, 2, 1, 1, 3, 2, '2020-01-10 23:59:59', 10);
    $this->insertOrderLine($pdo, 3, 3, 1, 1, 50, 1, '2019-12-31 23:59:59', 10);
    $this->insertOrderLine($pdo, 4, 4, 1, 1, 50, 1, '2020-01-11 00:00:00', 10);
    $this->insertOrderLine($pdo, 5, 5, 1, 0, 50, 1, '2020-01-04 00:00:00', 10);
    $this->insertOrderLine($pdo, 6, 6, 2, 1, 50, 1, '2020-01-04 00:00:00', 10);
    $pdo->exec("INSERT INTO ps_page (id_page, id_object, id_page_type) VALUES (1, 1, 3), (2, 1, 9), (3, 2, 3)");
    $pdo->exec("INSERT INTO ps_date_range (id_date_range, time_start, time_end) VALUES (1, '2020-01-02 00:00:00', '2020-01-02 23:59:59'), (2, '2020-01-03 00:00:00', '2020-01-03 23:59:59'), (3, '2019-12-01 00:00:00', '2019-12-01 23:59:59'), (4, '2020-01-04 00:00:00', '2020-01-04 23:59:59')");
    $pdo->exec('INSERT INTO ps_page_viewed (id_page, id_date_range, counter) VALUES (1, 1, 3), (1, 2, 4), (1, 3, 100), (2, 4, 50), (3, 4, 80)');

    $module = $this->gridModule(1, null, null, 0, 40);
    $module->getData();
    $this->assertCount(1, $module->getGridValues());
    $row = $module->getGridValues()[0];
    $this->assertSame('MUG-1', $row['reference']);
    $this->assertSame('Mug', $row['name']);
    $this->assertNotSame('Tasse', $row['name']);
    $this->assertNotSame('Wrong shop', $row['name']);
    $this->assertNumericEquals(4, $row['totalQuantitySold']);
    $this->assertSame('5.00 EUR', $row['avgPriceSold']);
    $this->assertSame('20.00 EUR', $row['totalPriceSold']);
    $this->assertSame('0.80', $row['averageQuantitySold']);
    $this->assertNumericEquals(7, $row['totalPageViewed']);
    $this->assertNumericEquals(7, $row['quantity']);
    $this->assertNumericEquals(1, $row['active']);
    $this->assertInternalType('int', $module->getTotalCount());
    $this->assertSame(1, $module->getTotalCount());
  }

  public function testDailyAverageStartsAtProductDateAdd()
  {
    $pdo = PsDb::pdo();
    $this->insertProduct($pdo, 1, 'A', '2020-01-03 00:00:00', 1);
    $pdo->exec("INSERT INTO ps_product_lang (id_product, id_lang, id_shop, name) VALUES (1, 1, 1, 'Item')");
    $this->insertOrderLine($pdo, 1, 1, 1, 1, 9, 1, '2020-01-04 00:00:00', 10);

    $module = $this->gridModule(1, null, null, 0, 40);
    $module->getData();
    $this->assertSame('3.00', $module->getGridValues()[0]['averageQuantitySold']);
  }

  public function testSortDirectionChangesPriceOrder()
  {
    $pdo = PsDb::pdo();
    $this->seedTwoProductsWithSales($pdo);
    $module = $this->gridModule(1, 'totalPriceSold', 'DESC', 0, 40);
    $module->getData();
    $this->assertSame('AAA', $module->getGridValues()[0]['reference']);
    $this->assertSame('ZZZ', $module->getGridValues()[1]['reference']);
    $queryDesc = $this->readQuery($module);
    $this->assertContains('ORDER BY `totalPriceSold` DESC', $queryDesc);

    StatsTestCaseHelper::resetEnvironment();
    $pdo = PsDb::pdo();
    $this->seedTwoProductsWithSales($pdo);
    $module = $this->gridModule(1, 'totalPriceSold', 'ASC', 0, 40);
    $module->getData();
    $this->assertSame('ZZZ', $module->getGridValues()[0]['reference']);
    $queryAsc = $this->readQuery($module);
    $this->assertContains('ORDER BY `totalPriceSold` ASC', $queryAsc);
  }

  public function testInvalidSortIsIgnored()
  {
    $pdo = PsDb::pdo();
    $this->seedTwoProductsWithSales($pdo);

    foreach (array('totalPriceSold2', 'name;drop') as $badSort) {
      $module = $this->gridModule(1, $badSort, null, 0, 40);
      $module->getData();
      $this->assertCount(2, $module->getGridValues());
      $query = $this->readQuery($module);
      $this->assertInternalType('string', $query);
      $this->assertNotSame('', $query);
      $this->assertNotContains('ORDER BY', $query);
      $this->assertNotContains('totalPriceSold2', $query);
      $this->assertNotContains('drop', $query);
    }
    $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM ps_orders')->fetchColumn());
  }

  public function testEmptySortDoesNotEmitOrderBy()
  {
    $pdo = PsDb::pdo();
    $this->seedTwoProductsWithSales($pdo);
    foreach (array(null, '') as $sort) {
      $module = $this->gridModule(1, $sort, null, 0, 40);
      $module->getData();
      $this->assertCount(2, $module->getGridValues());
      $query = $this->readQuery($module);
      $this->assertInternalType('string', $query);
      $this->assertNotContains('ORDER BY', $query);
    }
  }

  public function testInvalidDirectionIsNotAppended()
  {
    $pdo = PsDb::pdo();
    $this->seedTwoProductsWithSales($pdo);

    foreach (array('desc', 'DESC;DROP') as $badDir) {
      $module = $this->gridModule(1, 'totalPriceSold', $badDir, 0, 40);
      $module->getData();
      $this->assertCount(2, $module->getGridValues());
      $query = $this->readQuery($module);
      $this->assertContains('ORDER BY `totalPriceSold`', $query);
      $this->assertNotContains('ASC', $query);
      $this->assertNotContains('DESC', $query);
      $this->assertNotContains('DROP', $query);
    }
    $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM ps_orders')->fetchColumn());
  }

  public function testLimitOffsetAndFoundRows()
  {
    $pdo = PsDb::pdo();
    $this->seedThreeProducts($pdo);
    $module = $this->gridModule(1, 'reference', 'ASC', 1, 1);
    $module->getData();
    $this->assertCount(1, $module->getGridValues());
    $this->assertSame('MMM', $module->getGridValues()[0]['reference']);
    $this->assertInternalType('int', $module->getTotalCount());
    $this->assertSame(3, $module->getTotalCount());
    $this->assertContains('LIMIT 1, 1', $this->readQuery($module));
  }

  public function testNonUnsignedLimitIsOmitted()
  {
    $pdo = PsDb::pdo();
    $this->seedThreeProducts($pdo);

    $module = $this->gridModule(1, 'reference', 'ASC', 0, '2x');
    $module->getData();
    $this->assertCount(3, $module->getGridValues());
    $this->assertNotContains('LIMIT', $this->readQuery($module));

    $module = $this->gridModule(1, 'reference', 'ASC', -1, 1);
    $module->getData();
    $this->assertCount(3, $module->getGridValues());
    $this->assertNotContains('LIMIT', $this->readQuery($module));
  }

  public function testInactiveProductAndMissingStockStillListed()
  {
    $pdo = PsDb::pdo();
    $this->insertProduct($pdo, 1, 'X', '2019-01-01 00:00:00', 0);
    $pdo->exec("INSERT INTO ps_product_lang (id_product, id_lang, id_shop, name) VALUES (1, 1, 1, 'Inactive')");
    $this->insertOrderLine($pdo, 1, 1, 1, 1, 2, 1, '2020-01-04 00:00:00', 10);

    $module = $this->gridModule(1, null, null, 0, 40);
    $module->getData();
    $this->assertCount(1, $module->getGridValues());
    $row = $module->getGridValues()[0];
    $this->assertNumericEquals(0, $row['active']);
    $this->assertNumericEquals(0, $row['quantity']);
    $this->assertNumericEquals(2, $row['totalQuantitySold']);
    $this->assertSame('20.00 EUR', $row['totalPriceSold']);
  }

  public function testNoOrdersYieldsEmptyValuesAndZeroTotal()
  {
    $module = $this->gridModule(1, null, null, 0, 40);
    $module->getData();
    $this->assertInternalType('array', $module->getGridValues());
    $this->assertCount(0, $module->getGridValues());
    $this->assertInternalType('int', $module->getTotalCount());
    $this->assertSame(0, $module->getTotalCount());
  }

  private function gridModule($lang, $sort, $direction, $start, $limit)
  {
    $module = new StatsBestProductsFixture();
    $module->configureGrid(StatsTestCaseHelper::defaultEmployee(), $lang, $sort, $direction, $start, $limit);

    return $module;
  }

  private function readQuery(StatsBestProductsFixture $module)
  {
    $ref = new ReflectionProperty('statsbestproducts', 'query');
    $ref->setAccessible(true);

    return $ref->getValue($module);
  }

  private function insertProduct(PDO $pdo, $id, $reference, $dateAdd, $active)
  {
    $pdo->exec('INSERT INTO ps_product (id_product, reference) VALUES (' . (int) $id . ', ' . $pdo->quote($reference) . ')');
    $pdo->exec('INSERT INTO ps_product_shop (id_product, id_shop, date_add, active) VALUES (' . (int) $id . ', 1, ' . $pdo->quote($dateAdd) . ', ' . (int) $active . ')');
  }

  private function insertOrderLine(PDO $pdo, $orderId, $detailId, $shop, $valid, $qty, $conversion, $invoiceDate, $unitPrice)
  {
    $pdo->exec(
      'INSERT INTO ps_orders (id_order, id_shop, conversion_rate, valid, invoice_date) VALUES ('
      . (int) $orderId . ', ' . (int) $shop . ', ' . (float) $conversion . ', ' . (int) $valid . ', '
      . $pdo->quote($invoiceDate) . ')'
    );
    $pdo->exec(
      'INSERT INTO ps_order_detail (id_order_detail, id_order, product_id, product_quantity, unit_price_tax_excl) VALUES ('
      . (int) $detailId . ', ' . (int) $orderId . ', 1, ' . (int) $qty . ', ' . (float) $unitPrice . ')'
    );
  }

  private function seedTwoProductsWithSales(PDO $pdo)
  {
    $this->insertProduct($pdo, 1, 'AAA', '2019-01-01 00:00:00', 1);
    $this->insertProduct($pdo, 2, 'ZZZ', '2019-01-01 00:00:00', 1);
    $pdo->exec("INSERT INTO ps_product_lang (id_product, id_lang, id_shop, name) VALUES (1, 1, 1, 'A'), (2, 1, 1, 'Z')");
    $pdo->exec('INSERT INTO ps_order_detail (id_order_detail, id_order, product_id, product_quantity, unit_price_tax_excl) VALUES (1, 1, 1, 3, 10), (2, 2, 2, 1, 10)');
    $pdo->exec("INSERT INTO ps_orders (id_order, id_shop, conversion_rate, valid, invoice_date) VALUES (1, 1, 1, 1, '2020-01-04 00:00:00'), (2, 1, 1, 1, '2020-01-04 00:00:00')");
  }

  private function seedThreeProducts(PDO $pdo)
  {
    foreach (array(array(1, 'AAA'), array(2, 'MMM'), array(3, 'ZZZ')) as $row) {
      $this->insertProduct($pdo, $row[0], $row[1], '2019-01-01 00:00:00', 1);
      $pdo->exec('INSERT INTO ps_product_lang (id_product, id_lang, id_shop, name) VALUES (' . $row[0] . ', 1, 1, ' . $pdo->quote($row[1]) . ')');
    }
    $pdo->exec("INSERT INTO ps_orders (id_order, id_shop, conversion_rate, valid, invoice_date) VALUES (1,1,1,1,'2020-01-04 00:00:00'), (2,1,1,1,'2020-01-04 00:00:00'), (3,1,1,1,'2020-01-04 00:00:00')");
    $pdo->exec('INSERT INTO ps_order_detail (id_order_detail, id_order, product_id, product_quantity, unit_price_tax_excl) VALUES (1,1,1,1,5), (2,2,2,1,5), (3,3,3,1,5)');
  }

  private function assertNumericEquals($expected, $actual)
  {
    $this->assertInternalType('string', $actual);
    $this->assertNotSame('', $actual);
    $this->assertSame((string) (float) $expected, (string) (float) $actual);
  }
}
