<?php

declare(strict_types=1);

/**
 * Experiment 1: concurrency.
 *
 * Race: a payment_success webhook against the auto_cancel timeout worker, both acting on an order
 * that sits in pending_payment, as two separate PHP processes sharing one SQLite file.
 *
 * usage: php experiments/01-concurrency/run.php [iterations=100] [readDelayMs=0,5] [mode=none|lock]
 */

require __DIR__ . '/../../vendor/autoload.php';

use Tlab\StatemachinePlayground\Bootstrap;
use Tlab\StatemachinePlayground\Entity\Order;
use Tlab\StatemachinePlayground\Storage\SqliteStateStorage;
use Tlab\StatemachinePlayground\Storage\SqliteTimeoutScheduler;

$iterations = (int) ($argv[1] ?? 100);
$delaysMs = array_map('intval', explode(',', $argv[2] ?? '0,5'));
$mode = $argv[3] ?? 'none';

foreach ($delaysMs as $delayMs) {
    $tally = ['ok_webhook_won' => 0, 'ok_timeout_won' => 0, 'LOST_UPDATE' => 0, 'error' => 0, 'other' => 0];
    $finals = [];
    $samples = [];

    for ($i = 0; $i < $iterations; $i++) {
        $db = sys_get_temp_dir() . '/exp1-' . getmypid() . '-' . $i . '.sqlite';
        @unlink($db);
        $orderId = 'ORD-' . $i;

        // Arrange: an order that just entered pending_payment, so auto_cancel is scheduled.
        $runner = Bootstrap::createRunner(
            scheduler: new SqliteTimeoutScheduler($db),
            storage: new SqliteStateStorage($db, 'order'),
        );
        $runner->applyTo(new Order($orderId), 'place_order');

        // Act: both processes start at the same instant.
        $startAt = microtime(true) + 0.4;
        $procs = [];
        foreach (['webhook', 'timeout'] as $role) {
            $cmd = [PHP_BINARY, __DIR__ . '/worker.php', $db, $role, (string) $startAt, (string) ($delayMs * 1000), $orderId, $mode];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $procs[$role] = [$proc, $pipes];
        }

        $results = [];
        foreach ($procs as $role => [$proc, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            proc_close($proc);
            $results[$role] = json_decode(trim($stdout), true) ?? ['error' => 'no output: ' . $stderr, 'applied' => []];
        }

        $final = (new SqliteStateStorage($db, 'order'))->read(new Order($orderId));
        @unlink($db);

        $webhookApplied = count($results['webhook']['applied'] ?? []) > 0 && ($results['webhook']['applied'][0]['status'] ?? '') === 'Moved';
        $timeoutApplied = count($results['timeout']['applied'] ?? []) > 0 && ($results['timeout']['applied'][0]['status'] ?? '') === 'Moved';

        if (($results['webhook']['error'] ?? null) || ($results['timeout']['error'] ?? null)) {
            $class = 'error';
        } elseif ($webhookApplied && $timeoutApplied) {
            // Both transitions were reported as taken from pending_payment: one overwrote the other.
            $class = 'LOST_UPDATE';
        } elseif ($webhookApplied) {
            $class = 'ok_webhook_won';
        } elseif ($timeoutApplied) {
            $class = 'ok_timeout_won';
        } else {
            $class = 'other';
        }

        $tally[$class]++;
        $finals[$class . ' -> ' . $final] = ($finals[$class . ' -> ' . $final] ?? 0) + 1;
        if ($class !== 'ok_webhook_won' && $class !== 'ok_timeout_won' && count($samples) < 3) {
            $samples[] = ['class' => $class, 'final' => $final, 'results' => $results];
        }
    }

    echo "=== mode {$mode}, read delay {$delayMs} ms, {$iterations} iterations ===\n";
    foreach ($tally as $k => $v) {
        printf("  %-16s %d\n", $k, $v);
    }
    echo "  final states:\n";
    ksort($finals);
    foreach ($finals as $k => $v) {
        printf("    %-40s %d\n", $k, $v);
    }
    foreach ($samples as $s) {
        echo '  sample: ' . json_encode($s) . "\n";
    }
    echo "\n";
}
