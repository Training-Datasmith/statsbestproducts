<?php

class HookDisplayTest extends PHPUnit_Framework_TestCase
{
  protected function setUp()
  {
    StatsTestCaseHelper::resetEnvironment();
  }

  public function testHookRendersPanelAndEscapedQueryExportLink()
  {
    $_SERVER['REQUEST_URI'] = '/admin00/index.php?controller=AdminStats&module=statsbestproducts&token=abc" onclick="alert(1)';
    $module = new statsbestproducts();
    $html = $module->hookDisplayAdminStatsModules(array());

    $this->assertContains('<div class="panel-heading">', $html);
    $this->assertContains('Best-selling products', $html);
    $this->assertContains('class="btn btn-default export-csv"', $html);
    $this->assertContains('icon-cloud-upload', $html);
    $this->assertContains('CSV Export', $html);
    $this->assertContains('totalPriceSold', $html);
    $this->assertContains('&amp;export=1', $html);
    $this->assertContains('&quot;', $html);
    $this->assertNotContains('token=abc"', $html);

    $_GET['export'] = '0';
    $htmlFalsy = $module->hookDisplayAdminStatsModules(array());
    $this->assertContains('panel-heading', $htmlFalsy);
  }

  public function testHookInsertsQuestionMarkWhenUriHasNoQueryString()
  {
    $_SERVER['REQUEST_URI'] = '/admin00/index.php';
    $module = new statsbestproducts();
    $html = $module->hookDisplayAdminStatsModules(array());

    $this->assertContains('href="/admin00/index.php?export=1"', $html);
    $this->assertNotContains('&export=1', $html);
    $this->assertNotContains('&amp;export=1', $html);
  }

  public function testHookDoesNotNoticeWhenRequestUriIsUnset()
  {
    if (isset($_SERVER['REQUEST_URI'])) {
      unset($_SERVER['REQUEST_URI']);
    }
    $module = new statsbestproducts();
    $html = $module->hookDisplayAdminStatsModules(array());

    $this->assertInternalType('string', $html);
    $this->assertNotSame('', $html);
    $this->assertContains('href="?export=1"', $html);
    $this->assertNotContains('&export=1', $html);
  }

  public function testExportChildRunsGetDataAndDoesNotReturnHtml()
  {
    $root = dirname(__DIR__);
    $tmpDir = sys_get_temp_dir() . '/statsbest-export-' . getmypid() . '-' . mt_rand();
    mkdir($tmpDir, 0700, true);
    $script = $tmpDir . '/export-child.php';
    file_put_contents($script, $this->buildExportChildScript($root));
    chmod($script, 0700);

    $stdout = '';
    $stderr = '';
    $code = 0;
    StatsTestCaseHelper::runPhpChild($script, $stdout, $stderr, $code);

    $this->removeTree($tmpDir);

    $this->assertSame(0, $code);
    StatsTestCaseHelper::assertNoPhpDiagnostics($stdout, $stderr);
    $this->assertNotContains('RETURNED', $stdout);
    $this->assertNotContains('panel-heading', $stdout);
    $this->assertContains('Low', $stdout);
    $this->assertNotContains('Hidden', $stdout);
    $this->assertContains('Reference', $stdout);
    $this->assertContains('Sales', $stdout);

    $dataLine = '';
    foreach (preg_split('/\r\n|\r|\n/', $stdout) as $line) {
      if (strpos($line, 'ZZZ') === 0) {
        $dataLine = $line;
        break;
      }
    }
    $this->assertNotSame('', $dataLine);
  }

  private function buildExportChildScript($root)
  {
    $env = array(
      'DB_HOST' => getenv('DB_HOST') ? getenv('DB_HOST') : '127.0.0.1',
      'DB_PORT' => getenv('DB_PORT') ? getenv('DB_PORT') : '3306',
      'DB_USER' => getenv('DB_USER') ? getenv('DB_USER') : 'root',
      'DB_PASSWORD' => getenv('DB_PASSWORD') ? getenv('DB_PASSWORD') : 'statsbest',
      'DB_NAME' => getenv('DB_NAME') ? getenv('DB_NAME') : 'statsbest',
    );

    return '<?php
$root = ' . var_export($root, true) . ';
chdir($root);
putenv("DB_HOST=' . addslashes($env['DB_HOST']) . '");
putenv("DB_PORT=' . addslashes($env['DB_PORT']) . '");
putenv("DB_USER=' . addslashes($env['DB_USER']) . '");
putenv("DB_PASSWORD=' . addslashes($env['DB_PASSWORD']) . '");
putenv("DB_NAME=' . addslashes($env['DB_NAME']) . '");
require $root . "/tests/bootstrap.php";
$pdo = StatsTestCaseHelper::connect();
PsDb::setPdo($pdo);
Schema::truncateAll($pdo);
Schema::applySessionSettings($pdo);
$pdo->exec("INSERT INTO ps_product (id_product, reference) VALUES (1, \"AAA\"), (2, \"ZZZ\")");
$pdo->exec("INSERT INTO ps_product_shop (id_product, id_shop, date_add, active) VALUES (1, 1, \"2019-01-01 00:00:00\", 1), (2, 1, \"2019-01-01 00:00:00\", 1)");
$pdo->exec("INSERT INTO ps_product_lang (id_product, id_lang, id_shop, name) VALUES (1, 1, 1, \"Hidden\"), (1, 2, 1, \"High\"), (2, 2, 1, \"Low\")");
$pdo->exec("INSERT INTO ps_orders (id_order, id_shop, conversion_rate, valid, invoice_date) VALUES (1, 1, 1, 1, \"2020-01-04 00:00:00\"), (2, 1, 1, 1, \"2020-01-04 00:00:00\")");
$pdo->exec("INSERT INTO ps_order_detail (id_order_detail, id_order, product_id, product_quantity, unit_price_tax_excl) VALUES (1, 1, 1, 10, 10), (2, 2, 2, 1, 10)");
Context::getContext()->language->id = 2;
$module = new statsbestproducts();
$employee = new Employee();
$employee->stats_date_from = "2020-01-01";
$employee->stats_date_to = "2020-01-10";
$refEmp = new ReflectionProperty("ModuleGrid", "_employee");
$refEmp->setAccessible(true);
$refEmp->setValue($module, $employee);
$refSort = new ReflectionProperty("statsbestproducts", "_sort");
$refSort->setAccessible(true);
$refSort->setValue($module, "reference");
$refDir = new ReflectionProperty("ModuleGrid", "_direction");
$refDir->setAccessible(true);
$refDir->setValue($module, "ASC");
$_GET["export"] = "1";
$module->hookDisplayAdminStatsModules(array());
echo "RETURNED\\n";
';
  }

  private function removeTree($dir)
  {
    if (!is_dir($dir)) {
      return;
    }
    $items = scandir($dir);
    foreach ($items as $item) {
      if ($item === '.' || $item === '..') {
        continue;
      }
      $path = $dir . '/' . $item;
      if (is_dir($path)) {
        $this->removeTree($path);
      } else {
        @unlink($path);
      }
    }
    @rmdir($dir);
  }
}
