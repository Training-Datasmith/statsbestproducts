<?php

class FrontControllerGuardTest extends PHPUnit_Framework_TestCase
{
  protected function setUp()
  {
    StatsTestCaseHelper::resetEnvironment();
  }

  public function testModuleFileExitsWhenPsVersionIsNotDefined()
  {
    $this->runGuardScript(dirname(__DIR__) . '/statsbestproducts.php');
  }

  /**
   * @dataProvider directoryIndexProvider
   */
  public function testDirectoryIndexExitsBeforeReturning($relativePath)
  {
    $this->runGuardScript(dirname(__DIR__) . '/' . $relativePath);
  }

  public function directoryIndexProvider()
  {
    return array(
      array('index.php'),
      array('translations/index.php'),
      array('upgrade/index.php'),
      array('tests/index.php'),
      array('tests/phpstan/index.php'),
    );
  }

  private function runGuardScript($targetPath)
  {
    $tmpDir = sys_get_temp_dir() . '/statsbest-guard-' . getmypid() . '-' . mt_rand();
    mkdir($tmpDir, 0700, true);
    $script = $tmpDir . '/guard.php';
    $target = var_export($targetPath, true);
    $code = '<?php
register_shutdown_function(function () { echo "SHUTDOWN\\n"; });
include ' . $target . ';
echo "RETURNED\\n";
';
    file_put_contents($script, $code);
    chmod($script, 0700);

    $stdout = '';
    $stderr = '';
    $exitCode = 0;
    StatsTestCaseHelper::runPhpChild($script, $stdout, $stderr, $exitCode);

    @unlink($script);
    @rmdir($tmpDir);

    $this->assertSame(0, $exitCode);
    StatsTestCaseHelper::assertNoPhpDiagnostics($stdout, $stderr);
    $this->assertSame("SHUTDOWN\n", $stdout);
  }
}
