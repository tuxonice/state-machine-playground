<?php

declare(strict_types=1);

/**
 * One side of the race. Spawned by run.php, never run by hand.
 *
 * usage: php worker.php <db> <role: webhook|timeout> <startAtEpochFloat> <readDelayMicroseconds> <orderId> <mode: none|lock>
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/DelayingStorage.php';

use Tlab\StatemachinePlayground\Bootstrap;
use Tlab\StatemachinePlayground\Entity\Order;
use Tlab\StatemachinePlayground\Storage\SqliteStateStorage;
use Tlab\StatemachinePlayground\Storage\SqliteTimeoutScheduler;

[, $db, $role, $startAt, $delayUs, $orderId, $mode] = $argv;

$storage = new DelayingStorage(new SqliteStateStorage($db, 'order'), (int) $delayUs);
$scheduler = new SqliteTimeoutScheduler($db);
$runner = Bootstrap::createRunner(scheduler: $scheduler, storage: $storage);

// Everything above is setup. Wait for the shared start instant so both processes hit the library together.
while (microtime(true) < (float) $startAt) {
    // busy-wait on purpose: sleeping would add scheduling jitter
}

$out = ['role' => $role, 'applied' => [], 'error' => null];

$record = static fn($result) => [
    'status' => $result->getStatus()->name,
    'state' => $result->getState(),
    'events' => $result->getEvents(),
];

/** Per-order mutex. Blocks until the lock is free; released when the callback returns. */
$withOrderLock = static function (string $id, callable $fn) {
    $fp = fopen(sys_get_temp_dir() . '/exp1-lock-' . md5($id), 'c');
    flock($fp, LOCK_EX);
    try {
        return $fn();
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
};

try {
    if ($role === 'webhook') {
        $apply = fn() => $runner->applyTo(new Order($orderId), 'payment_success', ['payment_method' => 'card']);
        $result = $mode === 'lock' ? $withOrderLock($orderId, $apply) : $apply();
        $out['applied'][] = $record($result);
    } else {
        // The worker runs "31 minutes later" so the 30 minute auto_cancel is due.
        $now = (new DateTimeImmutable())->modify('+31 minutes');
        if ($mode === 'lock') {
            // processTimeouts() has no per-subject hook, so the loop has to be reimplemented
            // (pull, re-check state, apply) to be able to lock around each order.
            foreach ($scheduler->pullDue($now) as $timeout) {
                $subject = $timeout->getSubject();
                $withOrderLock($subject->getId(), function () use ($storage, $runner, $timeout, $subject, $record, &$out) {
                    if ($storage->read($subject) !== $timeout->getState()) {
                        return;
                    }
                    $out['applied'][] = $record($runner->applyTo($subject, $timeout->getEvent()));
                });
            }
        } else {
            foreach ($runner->processTimeouts($now) as $result) {
                $out['applied'][] = $record($result);
            }
        }
    }
} catch (Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
}

echo json_encode($out), "\n";
