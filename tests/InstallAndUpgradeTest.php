<?php

class InstallAndUpgradeTest extends PHPUnit_Framework_TestCase
{
  protected function setUp()
  {
    StatsTestCaseHelper::resetEnvironment();
  }

  public function testInstallRegistersDisplayHookWhenParentSucceeds()
  {
    Module::$parentInstallResult = true;
    Module::$registerHookResult = true;
    $module = new statsbestproducts();

    $this->assertTrue($module->install());
    $this->assertCount(1, $module->hookCalls);
    $this->assertSame('register', $module->hookCalls[0][0]);
    $this->assertSame('displayAdminStatsModules', $module->hookCalls[0][1]);
  }

  public function testInstallDoesNotRegisterWhenParentFails()
  {
    Module::$parentInstallResult = false;
    $module = new statsbestproducts();

    $this->assertFalse($module->install());
    $this->assertCount(0, $module->hookCalls);
  }

  public function testInstallReturnsFalseWhenRegisterHookFails()
  {
    Module::$parentInstallResult = true;
    Module::$registerHookResult = false;
    $module = new statsbestproducts();

    $this->assertFalse($module->install());
    $this->assertCount(1, $module->hookCalls);
    $this->assertSame('displayAdminStatsModules', $module->hookCalls[0][1]);
  }

  public function testUpgrade201UnregistersLegacyHookAndRegistersDisplayHook()
  {
    $stub = new UpgradeRecorderModule();

    $this->assertTrue(upgrade_module_2_0_1($stub));
    $this->assertCount(2, $stub->hookCalls);
    $this->assertSame('unregister', $stub->hookCalls[0][0]);
    $this->assertSame('AdminStatsModules', $stub->hookCalls[0][1]);
    $this->assertSame('register', $stub->hookCalls[1][0]);
    $this->assertSame('displayAdminStatsModules', $stub->hookCalls[1][1]);
  }
}

class UpgradeRecorderModule
{
  public $hookCalls = array();

  public function unregisterHook($hook)
  {
    $this->hookCalls[] = array('unregister', $hook);

    return true;
  }

  public function registerHook($hook)
  {
    $this->hookCalls[] = array('register', $hook);

    return true;
  }
}
