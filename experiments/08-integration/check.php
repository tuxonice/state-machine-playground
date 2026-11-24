<?php

declare(strict_types=1);

/**
 * CI check for state machine definitions.
 *
 * usage: php experiments/08-integration/check.php [--strict] [--container=path/to/container.php] [file-or-dir ...]
 *   default target: config/*-state-machine.json
 *   exit code 1 when there is an error (or any warning with --strict)
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/DefinitionChecker.php';

use Experiment08\DefinitionChecker;

$strict = false;
$container = null;
$targets = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--strict') {
        $strict = true;
    } elseif (str_starts_with($arg, '--container=')) {
        $container = require substr($arg, 12);
    } else {
        $targets[] = $arg;
    }
}
$targets = $targets ?: [__DIR__ . '/../../config'];
$files = [];
foreach ($targets as $t) {
    $files = array_merge($files, is_dir($t) ? (glob(rtrim($t, '/') . '/*.json') ?: []) : [$t]);
}

$checker = new DefinitionChecker($container);
$errors = $warnings = 0;
foreach ($files as $file) {
    $findings = $checker->checkFile($file);
    echo basename($file) . ($findings === [] ? ": ok\n" : ":\n");
    foreach ($findings as $f) {
        echo '  ' . strtoupper($f['level']) . " {$f['rule']} {$f['message']}\n";
        $f['level'] === 'error' ? $errors++ : $warnings++;
    }
}
echo "\n{$errors} error(s), {$warnings} warning(s)\n";
exit($errors > 0 || ($strict && $warnings > 0) ? 1 : 0);
