<?php

class ModuleMetadataTest extends PHPUnit_Framework_TestCase
{
  protected function setUp()
  {
    StatsTestCaseHelper::resetEnvironment();
  }

  public function testConstructorIdentityMatchesConfigXml()
  {
    $module = new statsbestproducts();

    $this->assertSame('statsbestproducts', $module->name);
    $this->assertSame('2.1.0', $module->version);
    $this->assertSame('PrestaShop', $module->author);
    $this->assertSame(0, (int) $module->need_instance);
    $this->assertSame('1.7.6.0', $module->ps_versions_compliancy['min']);
    $this->assertSame('1.7.6.9', $module->ps_versions_compliancy['max']);

    $xml = simplexml_load_file(dirname(__DIR__) . '/config.xml');
    $this->assertNotFalse($xml);
    $this->assertSame('2.1.0', (string) $xml->version);
    $this->assertSame((string) $xml->displayName, $module->displayName);

    $this->assertInternalType('string', $module->displayName);
    $this->assertNotSame('', $module->displayName);
    $this->assertInternalType('string', $module->description);
    $this->assertNotSame('', $module->description);
    $this->assertSame(
      'Enrich your stats with a small list of your best-sellers to better know your customers.',
      $module->description
    );
  }

  public function testColumnsArePassedToTheGridEngineInOrder()
  {
    $_SERVER['REQUEST_URI'] = '/admin00/index.php?controller=AdminStats';
    $module = new statsbestproducts();
    $html = $module->hookDisplayAdminStatsModules(array());
    $params = StatsTestCaseHelper::extractEngineParams($html);

    $this->assertSame('id_product', $params['id']);
    $this->assertSame('Best-selling products', $params['title']);
    $this->assertSame('totalPriceSold', $params['defaultSortColumn']);
    $this->assertSame('DESC', $params['defaultSortDirection']);
    $this->assertSame('An empty record-set was returned.', $params['emptyMessage']);
    $this->assertSame('Displaying {0} - {1} of {2}', $params['pagingMessage']);

    $expected = array(
      array('reference', 'Reference', 'left'),
      array('name', 'Name', 'left'),
      array('totalQuantitySold', 'Quantity sold', 'center'),
      array('avgPriceSold', 'Price sold', 'right'),
      array('totalPriceSold', 'Sales', 'right'),
      array('averageQuantitySold', 'Quantity sold in a day', 'center'),
      array('totalPageViewed', 'Page views', 'center'),
      array('quantity', 'Available quantity for sale', 'center'),
      array('active', 'Active', 'center'),
    );

    $this->assertCount(9, $params['columns']);
    for ($i = 0; $i < 9; $i++) {
      $col = $params['columns'][$i];
      $this->assertSame($expected[$i][0], $col['id']);
      $this->assertSame($expected[$i][0], $col['dataIndex']);
      $this->assertSame($expected[$i][1], $col['header']);
      $this->assertSame($expected[$i][2], $col['align']);
    }
  }
}
