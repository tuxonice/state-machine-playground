<?php

declare(strict_types=1);

/** Every rule must fire on its own fixture, and the clean fixture must pass. */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/DefinitionChecker.php';

use Experiment08\DefinitionChecker;

$checker = new DefinitionChecker();
$failures = 0;
foreach (glob(__DIR__ . '/fixtures/*.json') as $file) {
    $name = basename($file);
    $findings = $checker->checkFile($file);
    $rules = array_unique(array_column($findings, 'rule'));
    if (str_starts_with($name, 'clean')) {
        $ok = $findings === [];
        echo ($ok ? 'PASS ' : 'FAIL ') . "{$name} produces no findings" . ($ok ? '' : ': ' . json_encode($findings)) . "\n";
    } else {
        preg_match('/^(R\d+)-/', $name, $m);
        $ok = in_array($m[1], $rules, true);
        echo ($ok ? 'PASS ' : 'FAIL ') . "{$name} triggers {$m[1]}" . "  [" . ($findings ? $findings[0]['message'] : 'no findings') . "]\n";
    }
    $failures += $ok ? 0 : 1;
}
echo "\n" . ($failures === 0 ? 'ALL RULES FIRE ON THEIR FIXTURE' : "{$failures} FIXTURE(S) FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
