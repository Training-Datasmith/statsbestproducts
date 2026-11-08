<?php

class Employee
{
  public $id = 1;
  public $stats_date_from;
  public $stats_date_to;

  public function update()
  {
    throw new Exception('employee->update() must not run in tests');
  }
}

class Module
{
  public static $parentInstallResult = true;
  public static $registerHookResult = true;
  public static $unregisterHookResult = true;

  public $name = '';
  public $context;
  public $hookCalls = array();

  public function __construct()
  {
    if ($this->context === null) {
      $this->context = Context::getContext();
    }
  }

  public function install()
  {
    return self::$parentInstallResult;
  }

  public function registerHook($hook_name, $shop_list = null)
  {
    $this->hookCalls[] = array('register', $hook_name);

    return self::$registerHookResult;
  }

  public function unregisterHook($hook_id, $shop_list = null)
  {
    $this->hookCalls[] = array('unregister', $hook_id);

    return self::$unregisterHookResult;
  }

  protected function trans($id, array $parameters = array(), $domain = null, $locale = null)
  {
    $parameters['legacy'] = 'htmlspecialchars';

    return $this->getTranslator()->trans($id, $parameters, $domain, $locale);
  }

  protected function getTranslator()
  {
    return $this->context->getTranslator();
  }

  public static function resetStatics()
  {
    self::$parentInstallResult = true;
    self::$registerHookResult = true;
    self::$unregisterHookResult = true;
  }
}

class ModuleGraph extends Module
{
  protected $_employee;
  protected $_id_lang;

  public function setEmployee($id_employee)
  {
    $this->_employee = new Employee();
    $this->_employee->id = (int) $id_employee;
  }

  public function setLang($id_lang)
  {
    $this->_id_lang = $id_lang;
  }

  public function getDate()
  {
    return ModuleGraph::getDateBetween($this->_employee);
  }

  public function getLang()
  {
    return $this->_id_lang;
  }

  public static function getDateBetween($employee = null)
  {
    if (!($employee instanceof Employee)) {
      throw new Exception('employee stats dates must be set for tests');
    }
    if ($employee->stats_date_from === null || $employee->stats_date_from === ''
      || $employee->stats_date_to === null || $employee->stats_date_to === '') {
      throw new Exception('employee stats dates must be set for tests');
    }

    return ' \'' . pSQL($employee->stats_date_from) . ' 00:00:00\' AND \'' . pSQL($employee->stats_date_to) . ' 23:59:59\' ';
  }
}

abstract class ModuleGrid extends ModuleGraph
{
  protected $_values = array();
  protected $_totalCount = 0;
  protected $_sort = null;
  protected $_direction = null;
  protected $_start;
  protected $_limit;
  protected $_csv = '';

  public function engine($params)
  {
    $json = json_encode($params);

    return '<div class="stats-grid-engine"><script type="application/json" class="engine-params">'
      . htmlspecialchars($json, ENT_NOQUOTES, 'UTF-8')
      . '</script></div>';
  }

  public function csvExport($datas)
  {
    $this->_sort = $datas['defaultSortColumn'];
    $this->setLang(Context::getContext()->language->id);
    $this->getData();

    if (count($datas['columns'])) {
      foreach ($datas['columns'] as $column) {
        $this->_csv .= $column['header'] . ';';
      }
      $this->_csv = rtrim($this->_csv, ';') . "\n";

      foreach ($this->_values as $value) {
        foreach ($datas['columns'] as $column) {
          $this->_csv .= $value[$column['dataIndex']] . ';';
        }
        $this->_csv = rtrim($this->_csv, ';') . "\n";
      }
    }
    echo $this->_csv;
    exit;
  }

  abstract protected function getData();
}

class PsDb
{
  private static $instances = array();
  private $pdo;

  public function __construct(PDO $pdo = null)
  {
    $this->pdo = $pdo;
  }

  public static function setPdo(PDO $pdo)
  {
    self::$instances = array(
      'master' => new PsDb($pdo),
      'slave' => new PsDb($pdo),
    );
  }

  public static function getInstance($slave = false)
  {
    $key = $slave ? 'slave' : 'master';
    if (!isset(self::$instances[$key])) {
      self::$instances[$key] = new PsDb();
    }

    return self::$instances[$key];
  }

  public static function reset()
  {
    self::$instances = array();
  }

  public static function pdo()
  {
    $db = self::getInstance(false);
    if ($db->pdo === null) {
      throw new Exception('database not configured');
    }

    return $db->pdo;
  }

  public function executeS($sql)
  {
    if ($this->pdo === null) {
      throw new Exception('database not configured');
    }
    $stmt = $this->pdo->query($sql);
    if ($stmt === false) {
      $info = $this->pdo->errorInfo();
      throw new Exception(isset($info[2]) ? $info[2] : 'query failed');
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  public function getValue($sql)
  {
    $rows = $this->executeS($sql);
    if (count($rows) === 0) {
      return false;
    }
  $first = reset($rows);
    $values = array_values($first);

    return $values[0];
  }
}

class Db
{
  public static function getInstance($slave = false)
  {
    return PsDb::getInstance($slave);
  }

  public static function reset()
  {
    PsDb::reset();
  }
}

class Shop
{
  const SHARE_ORDER = 'share_order';

  public static function addSqlAssociation($table, $alias, $inner_join = true, $on = null, $force_not_default = false)
  {
    if ($table !== 'product' || $alias !== 'p') {
      return '';
    }

    return ' INNER JOIN ps_product_shop product_shop ON (product_shop.id_product = p.id_product AND product_shop.id_shop = 1)';
  }

  public static function addSqlRestriction($share = false, $alias = null)
  {
    if ($share === self::SHARE_ORDER && $alias === 'o') {
      return ' AND o.id_shop = 1 ';
    }

    return '';
  }

  public static function addSqlRestrictionOnLang($alias = null)
  {
    if ($alias === 'pl') {
      return ' AND pl.id_shop = 1 ';
    }

    return '';
  }
}

class Combination
{
  public static function isFeatureActive()
  {
    return true;
  }
}

class Product
{
  public static function sqlStock($product_alias, $product_attribute = null, $inner_join = false, Shop $shop = null)
  {
    $attr = (int) $product_attribute;

    return ' LEFT JOIN ps_stock_available stock ON (stock.id_product = p.id_product'
      . ' AND stock.id_product_attribute = ' . $attr
      . ' AND stock.id_shop = 1 )';
  }
}

class Page
{
  public static function getPageTypeByName($name)
  {
    if ($name === 'product') {
      return 3;
    }

    return 0;
  }
}

class Configuration
{
  private static $values = array(
    'PS_CURRENCY_DEFAULT' => 1,
  );

  public static function get($key)
  {
    if (!isset(self::$values[$key])) {
      throw new Exception('unexpected configuration key: ' . $key);
    }

    return self::$values[$key];
  }

  public static function reset()
  {
    self::$values = array(
      'PS_CURRENCY_DEFAULT' => 1,
    );
  }
}

class Currency
{
  public $id;
  public $iso_code;

  public function __construct($id)
  {
    $this->id = (int) $id;
    if ($this->id === 1) {
      $this->iso_code = 'EUR';
    } else {
      throw new Exception('unexpected currency id');
    }
  }
}

class PsLocale
{
  public function formatPrice($amount, $iso)
  {
    if (!is_string($iso) || $iso === '') {
      throw new Exception('invalid currency iso');
    }

    return number_format((float) $amount, 2, '.', '') . ' ' . $iso;
  }
}

class Language
{
  public $id = 1;
}

class PsTranslator
{
  public function trans($id, array $parameters = array(), $domain = null, $locale = null)
  {
    $legacy = null;
    if (isset($parameters['legacy'])) {
      $legacy = $parameters['legacy'];
      unset($parameters['legacy']);
    }

    $translated = $id;
    if ($legacy === 'htmlspecialchars') {
      $translated = htmlspecialchars($translated, ENT_NOQUOTES, 'UTF-8');
    } elseif ($legacy !== null) {
      $translated = call_user_func($legacy, $translated);
    }

    if (count($parameters) > 0 && preg_match('/%[0-9]+\$[a-z]/', $id)) {
      $translated = vsprintf($translated, $parameters);
    } elseif (count($parameters) > 0) {
      $translated = strtr($translated, $parameters);
    }

    return $translated;
  }
}

class Context
{
  private static $instance;
  public $language;
  private $locale;
  private $translator;

  public function __construct()
  {
    $this->language = new Language();
    $this->locale = new PsLocale();
    $this->translator = new PsTranslator();
  }

  public static function getContext()
  {
    if (self::$instance === null) {
      self::$instance = new Context();
    }

    return self::$instance;
  }

  public static function reset()
  {
    self::$instance = new Context();
  }

  public function getCurrentLocale()
  {
    return $this->locale;
  }

  public function getTranslator()
  {
    return $this->translator;
  }
}

class Tools
{
  public static function getValue($key, $default = false)
  {
    if (isset($_POST[$key])) {
      return $_POST[$key];
    }
    if (isset($_GET[$key])) {
      return $_GET[$key];
    }

    return $default;
  }

  public static function safeOutput($string)
  {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
  }
}

class Validate
{
  public static function IsName($name)
  {
    return preg_match('/^[^0-9!<>,;?=+()@#"°{}_$%:¤|]*$/u', $name) === 1;
  }

  public static function isSortDirection($value)
  {
    return $value !== null && ($value === 'ASC' || $value === 'DESC');
  }

  public static function IsUnsignedInt($value)
  {
    return (string) (int) $value === (string) $value && $value < 4294967296 && $value >= 0;
  }

  public static function isLoadedObject($object)
  {
    return is_object($object) && isset($object->id) && Validate::IsUnsignedInt($object->id);
  }
}

function pSQL($string)
{
  return addslashes($string);
}

function bqSQL($string)
{
  return str_replace('`', '\`', pSQL($string));
}

class StatsTestCaseHelper
{
  public static function resetEnvironment()
  {
    $_GET = array();
    $_POST = array();
    if (isset($_SERVER['REQUEST_URI'])) {
      unset($_SERVER['REQUEST_URI']);
    }

    Module::resetStatics();
    Configuration::reset();
    Context::reset();
    Db::reset();

    $pdo = StatsTestCaseHelper::connect();
    Schema::truncateAll($pdo);
    Schema::applySessionSettings($pdo);
    PsDb::setPdo($pdo);
  }

  public static function connect()
  {
    $host = getenv('DB_HOST') ? getenv('DB_HOST') : '127.0.0.1';
    $port = getenv('DB_PORT') ? getenv('DB_PORT') : '3306';
    $user = getenv('DB_USER') ? getenv('DB_USER') : 'root';
    $pass = getenv('DB_PASSWORD') ? getenv('DB_PASSWORD') : 'statsbest';
    $name = getenv('DB_NAME') ? getenv('DB_NAME') : 'statsbest';

    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8';
    $pdo = new PDO($dsn, $user, $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    Schema::createTables($pdo);
    Schema::applySessionSettings($pdo);

    return $pdo;
  }

  public static function defaultEmployee()
  {
    $employee = new Employee();
    $employee->stats_date_from = '2020-01-01';
    $employee->stats_date_to = '2020-01-10';

    return $employee;
  }

  public static function runPhpChild($scriptPath, &$stdout, &$stderr, &$exitCode)
  {
    $bin = defined('PHP_BINARY') ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($bin)
      . ' -d error_reporting=-1 -d display_errors=1 -d display_startup_errors=1 -d log_errors=0 '
      . escapeshellarg($scriptPath);

    $descriptors = array(
      0 => array('pipe', 'r'),
      1 => array('pipe', 'w'),
      2 => array('pipe', 'w'),
    );
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
      throw new Exception('could not start child process');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
  }

  public static function assertNoPhpDiagnostics($stdout, $stderr)
  {
    $pattern = '/(Notice:|Warning:|Deprecated:|Strict Standards:|Parse error:|Fatal error:)/';
    PHPUnit_Framework_Assert::assertNotRegExp($pattern, $stdout, 'unexpected PHP diagnostic on stdout');
    PHPUnit_Framework_Assert::assertNotRegExp($pattern, $stderr, 'unexpected PHP diagnostic on stderr');
  }

  public static function extractEngineParams($html)
  {
    if (!preg_match('/<script type="application\\/json" class="engine-params">([^<]*)<\\/script>/', $html, $m)) {
      throw new Exception('engine params not found in html');
    }
    $decoded = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);
    if (!is_array($decoded)) {
      throw new Exception('engine params json decode failed');
    }

    return $decoded;
  }
}
