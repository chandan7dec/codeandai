<?php

declare(strict_types=1);

/**
 * PHPUnit-compatible test runner for environments without composer.
 *
 * Usage: php tools/run_tests.php [unit|integration|all]
 * If vendor/bin/phpunit exists, prefer it — this runner is a fallback that
 * executes the identical test classes through the shim.
 */

require __DIR__ . '/../tests/bootstrap.php';

// Simple autoloader for includes/*.php service classes (no composer here).
spl_autoload_register(static function (string $class): void {
    $map = [
        'RegistrationService' => 'registration_service.php',
        'ClassManagementService' => 'class_management_service.php',
        'UpiService' => 'upi_service.php',
        'DashboardService' => 'dashboard_service.php',
        'AdminDashboardService' => 'admin_dashboard_service.php',
        'EmailService' => 'email_service.php',
        'TrainingResourceService' => 'training_resource_service.php',
    ];
    if (isset($map[$class])) {
        require_once __DIR__ . '/../includes/' . $map[$class];
    }
});

if (!class_exists('PHPUnit\Framework\TestCase')) {
    require __DIR__ . '/../tests/shim/phpunit_shim.php';
}
// Test support base classes (no autoloader without composer).
require_once __DIR__ . '/../tests/Support/DatabaseTestCase.php';

$suite = $argv[1] ?? 'all';

$testFiles = [];
foreach (['unit' => 'Tests\\Unit', 'integration' => 'Tests\\Integration'] as $dir => $namespace) {
    if ($suite !== 'all' && $suite !== $dir) {
        continue;
    }
    foreach (glob(__DIR__ . '/../tests/' . $dir . '/*Test.php') ?: [] as $file) {
        $class = basename($file, '.php');
        $testFiles[] = ['file' => $file, 'class' => $namespace . '\\' . $class];
    }
}

$totalPass = 0;
$totalFail = 0;
$totalError = 0;
$failures = [];
$started = microtime(true);

foreach ($testFiles as ['file' => $file, 'class' => $fqcn]) {
    require $file;

    echo '── ' . basename($file) . PHP_EOL;
    $instance = new $fqcn();
    foreach ($instance->runBare() as $result) {
        $timeMs = number_format($result['time'] * 1000, 1);
        if ($result['status'] === 'pass') {
            $totalPass++;
            echo "  ✓ {$result['name']} ({$timeMs} ms)" . PHP_EOL;
        } elseif ($result['status'] === 'fail') {
            $totalFail++;
            echo "  ✗ {$result['name']} ({$timeMs} ms)" . PHP_EOL;
            $failures[] = [basename($file), $result['name'], $result['message'] ?? ''];
        } else {
            $totalError++;
            echo "  ⚠ {$result['name']} ({$timeMs} ms)" . PHP_EOL;
            $failures[] = [basename($file), $result['name'], $result['message'] ?? 'error'];
        }
    }
}

$elapsed = number_format(microtime(true) - $started, 2);
echo PHP_EOL;
echo "Tests: " . ($totalPass + $totalFail + $totalError)
    . ", Pass: {$totalPass}, Failures: {$totalFail}, Errors: {$totalError} ({$elapsed}s)" . PHP_EOL;

if (!empty($failures)) {
    echo PHP_EOL . "Failures / errors:" . PHP_EOL;
    foreach ($failures as [$file, $name, $message]) {
        echo "• {$file}::{$name}" . PHP_EOL . '  ' . $message . PHP_EOL;
    }
    exit(1);
}
exit(0);
