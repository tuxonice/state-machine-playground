<?php

declare(strict_types=1);

/**
 * Experiment 7: scale and performance.
 *
 * P1 definition parse cost and what caching buys
 * P2 per-call cost of the runner, and how it grows with definition size
 * P3 a list screen: 1000 orders x guard-aware event filtering
 * P4 timeout backlog of 100k in memory
 * P5 our SQLite scheduler: cost per cancel / pullDue as the table grows, with and without indexes
 * P6 memory per loaded definition
 *
 * Numbers are medians of several repeats in Docker on one machine: compare them with each other,
 * do not treat them as absolute.
 *
 * usage: php experiments/07-scale/run.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Tlab\StatemachinePlayground\Entity\Order;
use Tlab\StatemachinePlayground\Storage\SqliteTimeoutScheduler;
use Tlab\StateMachine\Reader\DefinitionReader;
use Tlab\StateMachine\Scheduler\InMemoryTimeoutScheduler;
use Tlab\StateMachine\StateMachineRunner;

/** @return float median microseconds per call */
function bench(callable $fn, int $iterations, int $repeats = 5): float
{
    $fn(); // warm-up
    $samples = [];
    for ($r = 0; $r < $repeats; $r++) {
        $t = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $fn();
        }
        $samples[] = (hrtime(true) - $t) / 1000 / $iterations;
    }
    sort($samples);

    return $samples[intdiv(count($samples), 2)];
}

function us(float $v): string
{
    return $v >= 1000 ? sprintf('%.2f ms', $v / 1000) : sprintf('%.1f us', $v);
}

/** A chain of $n states; each has next, cancel and jump transitions: 3n transitions. */
function bigDefinition(int $n): array
{
    $states = [];
    $transitions = [];
    for ($i = 0; $i < $n; $i++) {
        $states[] = ['name' => "s{$i}"] + ($i === 0 ? ['isCurrent' => true] : []);
        $transitions[] = ['source' => "s{$i}", 'target' => 's' . (($i + 1) % $n), 'event' => 'next'];
        $transitions[] = ['source' => "s{$i}", 'target' => 'end', 'event' => 'cancel'];
        $transitions[] = ['source' => "s{$i}", 'target' => 's' . (($i + 7) % $n), 'event' => 'jump'];
    }
    $states[] = ['name' => 'end'];

    return [
        'name' => "big{$n}",
        'states' => $states,
        'transitions' => $transitions,
        'events' => [['name' => 'next', 'manual' => true], ['name' => 'cancel', 'manual' => true], ['name' => 'jump', 'manual' => true]],
    ];
}

$tmp = sys_get_temp_dir() . '/exp7-' . getmypid();
mkdir($tmp);
$orderFile = __DIR__ . '/../../config/order-state-machine.json';
$files = ['order (12 states, 17 transitions)' => [$orderFile, 200]];
foreach ([100 => 20, 1000 => 5, 3000 => 3] as $n => $iters) {
    $path = "{$tmp}/big{$n}.json";
    file_put_contents($path, json_encode(bigDefinition($n)));
    $files[sprintf('%d states, %d transitions', $n + 1, 3 * $n)] = [$path, $iters];
}

echo "### P1 definition parse cost per request, and caching\n";
$reader = new DefinitionReader();
printf("  new DefinitionReader(): %s\n", us(bench(fn() => new DefinitionReader(), 200)));
foreach ($files as $label => [$path, $iters]) {
    $json = file_get_contents($path);
    $model = $reader->readFile($path);
    $blob = serialize($model);
    printf("  %s\n", $label);
    printf("    json_decode only            %s\n", us(bench(fn() => json_decode($json, true), $iters)));
    printf("    readFile (validate + build) %s\n", us(bench(fn() => $reader->readFile($path), $iters)));
    printf("    unserialize cached model    %s   (blob %d KB)\n", us(bench(fn() => unserialize($blob), $iters)), intdiv(strlen($blob), 1024));
}

echo "\n### P2 per-call cost of the runner\n";
foreach (['order (12 states, 17 transitions)' => $orderFile, '1001 states, 3000 transitions' => "{$tmp}/big1000.json", '3001 states, 9000 transitions' => "{$tmp}/big3000.json"] as $label => $path) {
    $runner = new StateMachineRunner($reader->readFile($path));
    $isOrder = str_starts_with($label, 'order');
    $state = $isOrder ? 'confirmed' : 's500';
    $event = $isOrder ? 'start_processing' : 'next';
    $o = new Order('P2');
    printf("  %s\n", $label);
    printf("    applyTo (no guards, no listeners)  %s\n", us(bench(function () use ($runner, $o, $state, $event) {
        $o->setState($state);
        $runner->applyTo($o, $event);
    }, 2000)));
    printf("    can()                              %s\n", us(bench(fn() => $runner->can($state, $event), 2000)));
    printf("    availableEvents(state)             %s\n", us(bench(fn() => $runner->availableEvents($state), 2000)));
}

echo "\n### P3 list screen: 1000 orders, each asked for its allowed manual events\n";
$runner = new StateMachineRunner($reader->readFile($orderFile));
$orders = [];
foreach (['new', 'pending_payment', 'confirmed', 'processing', 'shipped', 'delivered'] as $i => $s) {
    for ($k = 0; $k < 167; $k++) {
        $o = new Order("L-{$i}-{$k}");
        $o->setState($s);
        $orders[] = $o;
    }
}
$t = hrtime(true);
$total = 0;
foreach ($orders as $o) {
    foreach ($runner->availableManualEventsFor($o) as $e) {
        $total += $runner->canApplyTo($o, $e, ['payment_method' => 'card']) ? 1 : 0;
    }
}
printf("  %d orders, %d allowed (order,event) pairs: %s total (%s per order)\n", count($orders), $total, us((hrtime(true) - $t) / 1000), us((hrtime(true) - $t) / 1000 / count($orders)));

echo "\n### P4 timeout backlog of 100k in memory (library InMemoryTimeoutScheduler, state on the object)\n";
$sched = new InMemoryTimeoutScheduler();
$runner = new StateMachineRunner($reader->readFile($orderFile), scheduler: $sched);
$base = memory_get_usage(true);
$t = hrtime(true);
$subjects = [];
for ($i = 0; $i < 100000; $i++) {
    $o = new Order("B-{$i}");
    $runner->applyTo($o, 'place_order');      // moves to pending_payment, schedules auto_cancel
    $subjects[] = $o;
}
$seed = (hrtime(true) - $t) / 1e9;
$t = hrtime(true);
$results = $runner->processTimeouts((new DateTimeImmutable())->modify('+31 minutes'));
$proc = (hrtime(true) - $t) / 1e9;
printf("  seeding 100k orders (place_order + schedule): %.2f s\n  processTimeouts over 100k due: %.2f s, %d applied\n  memory above baseline: %.0f MB (peak %.0f MB)\n",
    $seed, $proc, count($results), (memory_get_usage(true) - $base) / 1048576, memory_get_peak_usage(true) / 1048576);
unset($subjects, $results);

echo "\n### P5 our SQLite scheduler as the table grows (cancel of one order, pullDue with nothing due)\n";
foreach ([1000, 10000, 100000] as $n) {
    foreach (['no indexes' => false, 'with indexes' => true] as $label => $indexed) {
        $db = "{$tmp}/p5-{$n}-" . ($indexed ? 'i' : 'n') . '.sqlite';
        $sc = new SqliteTimeoutScheduler($db);
        $pdo = new PDO("sqlite:{$db}");
        $pdo->beginTransaction();
        $ins = $pdo->prepare("INSERT INTO scheduled_timeouts (order_id, state, event, due_at) VALUES (?, 'pending_payment', 'auto_cancel', ?)");
        $due = date('Y-m-d H:i:s', time() + 1800);
        for ($i = 0; $i < $n; $i++) {
            $ins->execute(["O-{$i}", $due]);
        }
        $pdo->commit();
        if ($indexed) {
            $pdo->exec('CREATE INDEX idx_timeouts_order ON scheduled_timeouts (order_id)');
            $pdo->exec('CREATE INDEX idx_timeouts_due ON scheduled_timeouts (due_at)');
        }
        $victim = new Order('O-' . intdiv($n, 2));
        $cancel = bench(function () use ($sc, $victim) { $sc->cancel($victim); }, 20, 3);
        $pull = bench(fn() => $sc->pullDue(new DateTimeImmutable()), 20, 3);
        printf("  %6d rows, %-12s cancel %s   pullDue(not due) %s\n", $n, $label, us($cancel), us($pull));
    }
}

echo "\n### P6 memory per loaded definition\n";
$before = memory_get_usage();
$keep = [];
for ($i = 0; $i < 100; $i++) {
    $keep[] = new StateMachineRunner($reader->readFile($orderFile));
}
printf("  100 order runners: %.1f MB total, %.0f KB each\n", (memory_get_usage() - $before) / 1048576, (memory_get_usage() - $before) / 100 / 1024);
$before = memory_get_usage();
$big = new StateMachineRunner($reader->readFile("{$tmp}/big3000.json"));
printf("  1 runner with 3001 states / 9000 transitions: %.1f MB\n\n", (memory_get_usage() - $before) / 1048576);
