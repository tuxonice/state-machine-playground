<?php

declare(strict_types=1);

/**
 * Experiment 3: timeout worker robustness.
 *
 * T1 worker is killed mid-batch
 * T2 one order fails permanently (poison) in the middle of a batch
 * T3 two workers run at the same time
 * T4 backlog after downtime (many timeouts due at once)
 * T5 a due timeout whose condition does not pass
 *
 * Each of T1-T4 runs with the library loop (raw) and a lease/ack loop (safe).
 *
 * usage: php experiments/03-timeouts/run.php
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/support.php';

use Experiment03\LeasingScheduler;
use Experiment03\NeverCondition;
use Tlab\StatemachinePlayground\Bootstrap;
use Tlab\StatemachinePlayground\Entity\Order;
use Tlab\StatemachinePlayground\Storage\SqliteStateStorage;
use Tlab\StatemachinePlayground\Storage\SqliteTimeoutScheduler;
use Tlab\StateMachine\Reader\DefinitionReader;
use Tlab\StateMachine\StateMachineRunner;

function seed(int $n): string
{
    $db = sys_get_temp_dir() . '/exp3-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.sqlite';
    new SqliteStateStorage($db, 'order');   // creates the tables
    new SqliteTimeoutScheduler($db);
    new LeasingScheduler($db);              // schema migration: adds claimed_until / attempts before any worker starts
    $pdo = new PDO("sqlite:{$db}");
    $pdo->beginTransaction();
    $st = $pdo->prepare("INSERT INTO order_states (order_id, machine, state, updated_at) VALUES (?, 'order', 'pending_payment', ?)");
    $tm = $pdo->prepare("INSERT INTO scheduled_timeouts (order_id, state, event, due_at) VALUES (?, 'pending_payment', 'auto_cancel', ?)");
    $placed = date('Y-m-d H:i:s');
    $due = date('Y-m-d H:i:s', time() + 1800);
    for ($i = 1; $i <= $n; $i++) {
        $st->execute(["ORD-{$i}", $placed]);
        $tm->execute(["ORD-{$i}", $due]);
    }
    $pdo->commit();

    return $db;
}

/** @param list<array{mode: string, offset?: int, crash?: int, poison?: string, delayMs?: int, batch?: int}> $workers */
function spawn(string $db, array $workers): array
{
    $startAt = microtime(true) + 0.5;
    $procs = [];
    foreach ($workers as $i => $w) {
        $cmd = [
            PHP_BINARY, __DIR__ . '/worker.php', $db, $w['mode'], (string) $startAt, (string) ($w['offset'] ?? 0),
            (string) ($w['crash'] ?? 0), $w['poison'] ?? '-', (string) (($w['delayMs'] ?? 0) * 1000), (string) ($w['batch'] ?? 100),
        ];
        $procs[$i] = [proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes];
    }
    $out = [];
    foreach ($procs as $i => [$proc, $pipes]) {
        $stdout = trim(stream_get_contents($pipes[1]));
        $stderr = trim(stream_get_contents($pipes[2]));
        $code = proc_close($proc);
        $decoded = $stdout !== '' ? json_decode($stdout, true) : null;
        $out[$i] = $decoded ?? ['crashed' => true, 'exit' => $code, 'stderr' => $stderr ?: $stdout];
    }

    return $out;
}

function report(string $db, array $results, int $total): string
{
    $pdo = new PDO("sqlite:{$db}");
    $count = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
    $cancelled = $count("SELECT COUNT(*) FROM order_states WHERE state = 'cancelled'");
    $stuck = $count("SELECT COUNT(*) FROM order_states WHERE state = 'pending_payment'
                     AND order_id NOT IN (SELECT order_id FROM scheduled_timeouts)");
    $waiting = $count("SELECT COUNT(*) FROM order_states WHERE state = 'pending_payment'
                       AND order_id IN (SELECT order_id FROM scheduled_timeouts)");
    $writes = array_sum(array_map(fn($r) => $r['writes'] ?? 0, $results));

    return sprintf(
        'cancelled=%d/%d  STUCK(no timeout left)=%d  still-scheduled=%d  duplicate-applies=%d',
        $cancelled, $total, $stuck, $waiting, max(0, $writes - $cancelled)
    );
}

function describe(array $results): string
{
    $parts = [];
    foreach ($results as $i => $r) {
        if (isset($r['crashed'])) {
            $parts[] = "w{$i}: CRASHED (exit {$r['exit']})" . (!empty($r['stderr']) ? ' ' . substr($r['stderr'], 0, 120) : '');
        } else {
            $parts[] = "w{$i}: " . ($r['error'] ? "exception ({$r['error']})" : 'ok')
                . ($r['errors'] ? ", {$r['errors']} item errors" : '')
                . " {$r['seconds']}s {$r['peak_mb']}MB";
        }
    }

    return implode('; ', $parts);
}

$N = 50;

echo "### T1 worker killed after 10 of {$N}\n";
foreach (['raw', 'safe'] as $mode) {
    $db = seed($N);
    $r = spawn($db, [['mode' => $mode, 'crash' => 10]]);
    printf("  [%-4s] %s\n         %s\n", $mode, describe($r), report($db, $r, $N));
    if ($mode === 'safe') {
        // Restart after the 60s lease has expired.
        $r2 = spawn($db, [['mode' => 'safe', 'offset' => 120]]);
        printf("  [safe, after restart + lease expiry] %s\n         %s\n", describe($r2), report($db, array_merge($r, $r2), $N));
    }
}

echo "\n### T2 order ORD-25 always fails (poison), {$N} due\n";
foreach (['raw', 'safe'] as $mode) {
    $db = seed($N);
    $r = spawn($db, [['mode' => $mode, 'poison' => 'ORD-25']]);
    printf("  [%-4s] %s\n         %s\n", $mode, describe($r), report($db, $r, $N));
    if ($mode === 'safe') {
        // Two more rounds after lease expiry: the poison row is retried, then dead-lettered.
        $all = $r;
        foreach ([120, 240, 360] as $off) {
            $all = array_merge($all, spawn($db, [['mode' => 'safe', 'offset' => $off, 'poison' => 'ORD-25']]));
        }
        $dead = (int) (new PDO("sqlite:{$db}"))->query('SELECT COUNT(*) FROM scheduled_timeouts WHERE attempts >= 3')->fetchColumn();
        printf("  [safe, 3 retries later] %s  dead-lettered=%d\n", report($db, $all, $N), $dead);
    }
}

echo "\n### T3 two workers at once, {$N} due, 2 ms per read\n";
foreach (['raw', 'safe'] as $mode) {
    $db = seed($N);
    $r = spawn($db, [['mode' => $mode, 'delayMs' => 2], ['mode' => $mode, 'delayMs' => 2]]);
    printf("  [%-4s] %s\n         %s\n", $mode, describe($r), report($db, $r, $N));
}

$B = 3000;
echo "\n### T4 backlog of {$B} due at once (downtime)\n";
foreach (['raw', 'safe'] as $mode) {
    $db = seed($B);
    $r = spawn($db, [['mode' => $mode, 'batch' => 100]]);
    printf("  [%-4s] %s\n         %s\n", $mode, describe($r), report($db, $r, $B));
}

echo "\n### T5 due timeout whose condition fails\n";
$json = json_encode([
    'name' => 't5',
    'states' => [['name' => 'open', 'isCurrent' => true], ['name' => 'closed']],
    'transitions' => [['source' => 'open', 'target' => 'closed', 'event' => 'expire', 'condition' => NeverCondition::class]],
    'events' => [['name' => 'expire', 'timeout' => '1 minutes']],
]);
$db = sys_get_temp_dir() . '/exp3-t5-' . getmypid() . '.sqlite';
$sched = new SqliteTimeoutScheduler($db);
$runner = StateMachineRunner::fromJson($json, storage: new SqliteStateStorage($db, 't5'), scheduler: $sched);
$order = new Order('X-1');
(new SqliteStateStorage($db, 't5'))->write($order, 'open');
$runner->scheduleTimeouts($order);
$pendingBefore = count($sched->all());
$results = $runner->processTimeouts((new DateTimeImmutable())->modify('+2 minutes'));
$pendingAfter = count($sched->all());
printf("  timeouts scheduled=%d, applied results=%d (status %s), left in scheduler afterwards=%d\n  order state: %s\n",
    $pendingBefore, count($results), isset($results[0]) ? $results[0]->getStatus()->name : 'n/a', $pendingAfter,
    (new SqliteStateStorage($db, 't5'))->read(new Order('X-1')));
echo "\n";
