<?php

declare(strict_types=1);

/**
 * One timeout worker process. Spawned by run.php.
 *
 * usage: php worker.php <db> <mode: raw|safe> <startAt> <nowOffsetSeconds> <crashAfterWrites> <poisonOrderId|-> <readDelayUs> <batch>
 *
 * raw:  $runner->processTimeouts()                      (library loop + pullDue() that deletes first)
 * safe: claim a batch, apply each, ack on success       (lease, per-timeout try/catch, dead letter)
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/support.php';

use Experiment03\FaultStorage;
use Experiment03\LeasingScheduler;
use Tlab\StatemachinePlayground\Bootstrap;
use Tlab\StatemachinePlayground\Entity\Order;
use Tlab\StatemachinePlayground\Storage\SqliteStateStorage;
use Tlab\StatemachinePlayground\Storage\SqliteTimeoutScheduler;

[, $db, $mode, $startAt, $offset, $crashAfter, $poison, $delayUs, $batch] = $argv;

$storage = new FaultStorage(new SqliteStateStorage($db, 'order'), (int) $crashAfter, $poison === '-' ? '' : $poison, (int) $delayUs);
$now = (new DateTimeImmutable())->modify('+31 minutes')->modify("+{$offset} seconds");

$out = ['mode' => $mode, 'error' => null, 'errors' => 0];
$t0 = microtime(true);

if ($mode === 'raw') {
    $runner = Bootstrap::createRunner(scheduler: new SqliteTimeoutScheduler($db), storage: $storage);
} else {
    $leasing = new LeasingScheduler($db);
    $runner = Bootstrap::createRunner(scheduler: $leasing, storage: $storage);
}

while (microtime(true) < (float) $startAt) {
    // wait for the shared start instant
}

try {
    if ($mode === 'raw') {
        $runner->processTimeouts($now);
    } else {
        while (($claims = $leasing->claim($now, (int) $batch, 60)) !== []) {
            foreach ($claims as $c) {
                try {
                    $order = new Order($c['order_id']);
                    // Same stale check the library does: skip when the order is no longer in the scheduled state.
                    if ($storage->read($order) === $c['state']) {
                        $runner->applyTo($order, $c['event']);
                    }
                    $leasing->ack($c['id']);
                } catch (Throwable $e) {
                    $out['errors']++; // row stays leased; it is retried after the lease, then dead-lettered
                }
            }
        }
    }
} catch (Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
}

$out['writes'] = $storage->writes;
$out['seconds'] = round(microtime(true) - $t0, 2);
$out['peak_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
echo json_encode($out), "\n";
