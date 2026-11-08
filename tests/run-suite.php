<?php

if (php_sapi_name() !== 'cli') {
  fwrite(STDERR, "run-suite.php must run from CLI\n");
  exit(1);
}

error_reporting(-1);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
$expectedFiles = array(
  $root . '/tests/FrontControllerGuardTest.php',
  $root . '/tests/GetDataTest.php',
  $root . '/tests/HookDisplayTest.php',
  $root . '/tests/InstallAndUpgradeTest.php',
  $root . '/tests/ModuleMetadataTest.php',
);
$expectedTestCount = 26;
$bootstrap = $root . '/tests/bootstrap.php';

if ($argc < 2) {
  fwrite(STDERR, "usage: php run-suite.php /path/to/phpunit.phar\n");
  exit(1);
}

$phpunitPhar = $argv[1];
if (!is_file($phpunitPhar)) {
  fwrite(STDERR, "phpunit phar not found: $phpunitPhar\n");
  exit(1);
}

verifyPhpunitPhar($phpunitPhar);
assertTestFileDrift($root, $expectedFiles);

$runDir = tempdir();
$orderedXml = $runDir . '/phpunit-ordered.xml';
$shuffledXml = $runDir . '/phpunit-shuffled.xml';
$junitOrdered = $runDir . '/junit-ordered.xml';
$junitShuffled = $runDir . '/junit-shuffled.xml';

writePhpunitXml($orderedXml, $bootstrap, $expectedFiles, $junitOrdered);
$orderedOutput = runPhpunit($phpunitPhar, $orderedXml);
$orderedOk = extractOkLine($orderedOutput);
assertCleanSummary($orderedOutput, $expectedTestCount);
assertJUnit($junitOrdered, $expectedTestCount);

$shuffledFiles = $expectedFiles;
$seed = getenv('STATS_TEST_SEED') ? (int) getenv('STATS_TEST_SEED') : 20261108;
srand($seed);
shuffle($shuffledFiles);
writePhpunitXml($shuffledXml, $bootstrap, $shuffledFiles, $junitShuffled);
$shuffledOutput = runPhpunit($phpunitPhar, $shuffledXml);
$shuffledOk = extractOkLine($shuffledOutput);
assertCleanSummary($shuffledOutput, $expectedTestCount);
assertJUnit($junitShuffled, $expectedTestCount);

if ($orderedOk !== $shuffledOk) {
  fwrite(STDERR, "ordered OK line ($orderedOk) differs from shuffled ($shuffledOk)\n");
  exit(1);
}

echo "SUITE_OK: $orderedOk (seed $seed)\n";
exit(0);

function tempdir()
{
  $dir = sys_get_temp_dir() . '/statsbest-phpunit-' . getmypid() . '-' . mt_rand();
  if (!mkdir($dir, 0700, true)) {
    fwrite(STDERR, "could not create temp dir\n");
    exit(1);
  }

  return $dir;
}

function verifyPhpunitPhar($pharPath)
{
  $expectedSha = 'ab8bc3017a64cf75a2112f5e089a1d5b0c4cc2116556d60c1af5e3f584e85c5e';
  $hash = hash_file('sha256', $pharPath);
  if ($hash !== $expectedSha) {
    fwrite(STDERR, "phpunit phar sha256 mismatch: $hash\n");
    exit(1);
  }

  $work = sys_get_temp_dir() . '/statsbest-gpg-' . getmypid();
  mkdir($work, 0700, true);
  $gpgHome = $work . '/gnupg';
  mkdir($gpgHome, 0700);

  $gpgAsc = $work . '/gpg.asc';
  $sig = $work . '/phpunit.phar.asc';
  file_put_contents($gpgAsc, file_get_contents('https://sebastian-bergmann.de/gpg.asc'));
  file_put_contents($sig, file_get_contents('https://phar.phpunit.de/phpunit-4.8.36.phar.asc'));

  $cmdImport = 'gpg --homedir ' . escapeshellarg($gpgHome)
    . ' --batch --import ' . escapeshellarg($gpgAsc) . ' 2>&1';
  exec($cmdImport, $outImport, $codeImport);
  if ($codeImport !== 0) {
    fwrite(STDERR, "gpg import failed\n");
    exit(1);
  }

  $statusFile = $work . '/gpg-status.txt';
  $cmdVerify = 'gpg --homedir ' . escapeshellarg($gpgHome)
    . ' --batch --status-fd 1 --verify ' . escapeshellarg($sig) . ' ' . escapeshellarg($pharPath)
    . ' > ' . escapeshellarg($statusFile) . ' 2>' . escapeshellarg($work . '/gpg-stderr.txt');
  exec($cmdVerify, $dummy, $codeVerify);
  if ($codeVerify !== 0) {
    fwrite(STDERR, "gpg verify failed\n");
    exit(1);
  }

  $status = file_get_contents($statusFile);
  $valid = false;
  foreach (preg_split('/\r\n|\r|\n/', $status) as $line) {
    if (strpos($line, '[GNUPG:] VALIDSIG') === 0) {
      $parts = preg_split('/\s+/', trim($line));
      $fingerprint = end($parts);
      if ($fingerprint === 'D8406D0D82947747293778314AA394086372C20A') {
        $valid = true;
      }
    }
  }
  if (!$valid) {
    fwrite(STDERR, "missing VALIDSIG for expected phpunit signing key\n");
    exit(1);
  }
}

function assertTestFileDrift($root, $expectedFiles)
{
  $found = glob($root . '/tests/*Test.php');
  sort($found);
  $expected = $expectedFiles;
  sort($expected);
  if ($found !== $expected) {
    fwrite(STDERR, "test file drift detected\n");
    exit(1);
  }
}

function writePhpunitXml($path, $bootstrap, $files, $junitPath)
{
  $doc = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
  $doc .= '<phpunit bootstrap="' . htmlspecialchars($bootstrap, ENT_QUOTES, 'UTF-8') . '"'
    . ' colors="true"'
    . ' convertErrorsToExceptions="true"'
    . ' convertNoticesToExceptions="true"'
    . ' convertWarningsToExceptions="true"'
    . ' stopOnFailure="false"'
    . '>' . "\n";
  $doc .= '  <testsuites>' . "\n";
  $doc .= '    <testsuite name="module-tests">' . "\n";
  foreach ($files as $file) {
    $doc .= '      <file>' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '</file>' . "\n";
  }
  $doc .= '    </testsuite>' . "\n";
  $doc .= '  </testsuites>' . "\n";
  $doc .= '  <logging>' . "\n";
  $doc .= '    <log type="junit" target="' . htmlspecialchars($junitPath, ENT_QUOTES, 'UTF-8') . '"/>' . "\n";
  $doc .= '  </logging>' . "\n";
  $doc .= '</phpunit>' . "\n";
  file_put_contents($path, $doc);
}

function runPhpunit($phar, $xmlPath)
{
  $php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
  $cmd = escapeshellarg($php)
    . ' -d error_reporting=-1 -d display_errors=1 -d display_startup_errors=1 '
    . escapeshellarg($phar)
    . ' --configuration ' . escapeshellarg($xmlPath);
  $output = array();
  exec($cmd . ' 2>&1', $output, $code);
  $joined = implode("\n", $output);
  if ($code !== 0) {
    fwrite(STDERR, $joined . "\n");
    fwrite(STDERR, "phpunit exited with $code\n");
    exit(1);
  }

  return $joined;
}

function extractOkLine($output)
{
  if (!preg_match('/^OK \((\d+) tests, (\d+) assertions\)$/m', $output, $m)) {
    fwrite(STDERR, "missing OK summary line\n" . $output . "\n");
    exit(1);
  }

  return 'OK (' . $m[1] . ' tests, ' . $m[2] . ' assertions)';
}

function assertCleanSummary($output, $expectedTestCount)
{
  assertNoPhpDiagnosticsInOutput($output);
  $bad = array('FAILURES!', 'Skipped:', 'Incomplete:', 'Risky:', 'Warnings:');
  foreach ($bad as $token) {
    if (strpos($output, $token) !== false) {
      fwrite(STDERR, "forbidden token in phpunit output: $token\n");
      exit(1);
    }
  }
  if (!preg_match('/^OK \(' . (int) $expectedTestCount . ' tests, \d+ assertions\)$/m', $output)) {
    fwrite(STDERR, "unexpected OK summary (expected $expectedTestCount tests)\n" . $output . "\n");
    exit(1);
  }
}

function assertNoPhpDiagnosticsInOutput($output)
{
  if (preg_match('/(Notice:|Warning:|Deprecated:|Strict Standards:|Parse error:|Fatal error:)/', $output)) {
    fwrite(STDERR, "php diagnostic found in phpunit captured output\n" . $output . "\n");
    exit(1);
  }
}

function assertJUnit($path, $expectedTests)
{
  if (!is_file($path)) {
    fwrite(STDERR, "junit file missing\n");
    exit(1);
  }
  $xml = simplexml_load_file($path);
  if ($xml === false) {
    fwrite(STDERR, "invalid junit xml\n");
    exit(1);
  }
  $cases = $xml->xpath('//testcase');
  $tests = is_array($cases) ? count($cases) : 0;
  $errors = 0;
  $failures = 0;
  foreach ($cases as $case) {
    if (isset($case->error)) {
      $errors++;
    }
    if (isset($case->failure)) {
      $failures++;
    }
  }
  if ($tests !== $expectedTests || $errors !== 0 || $failures !== 0) {
    fwrite(STDERR, "junit mismatch tests=$tests errors=$errors failures=$failures\n");
    exit(1);
  }
  foreach ($xml->xpath('//testcase') as $case) {
    if (isset($case->skipped) || isset($case->error) || isset($case->failure)) {
      fwrite(STDERR, "junit contains skipped/error/failure\n");
      exit(1);
    }
  }
}
