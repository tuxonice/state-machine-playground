<?php

declare(strict_types=1);

/**
 * Experiment 8, part A: a Doctrine ORM entity as the state machine subject.
 *
 * usage: composer update -d experiments/08-integration   (once)
 *        php experiments/08-integration/run.php
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/vendor/autoload.php';
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'Experiment08\\')) {
        $file = __DIR__ . '/src/' . substr($class, 13) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Experiment08\Charge;
use Experiment08\DoctrineTimeoutScheduler;
use Experiment08\OrderEntity;
use Tlab\StatemachinePlayground\Storage\SqliteTimeoutScheduler;
use Tlab\StateMachine\Reader\DefinitionReader;
use Tlab\StateMachine\StateMachineRunner;
use Tlab\StateMachine\Scheduler\TimeoutScheduler;

$failures = 0;
function check(string $title, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  PASS ' : '  FAIL ') . $title . ($detail !== '' ? "  [{$detail}]" : '') . "\n";
    $failures += $ok ? 0 : 1;
}
function note(string $text): void
{
    echo "  NOTE {$text}\n";
}

function newEm(string $db): EntityManager
{
    $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/src'], true, sys_get_temp_dir() . '/exp8-proxies');
    $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $db], $config);

    return new EntityManager($conn, $config);
}

function freshDb(): string
{
    $db = sys_get_temp_dir() . '/exp8-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.sqlite';
    $em = newEm($db);
    (new SchemaTool($em))->createSchema([$em->getClassMetadata(OrderEntity::class)]);
    DoctrineTimeoutScheduler::createTable($em);

    return $db;
}

function machine(?TimeoutScheduler $scheduler): StateMachineRunner
{
    $def = [
        'name' => 'doctrine-order',
        'states' => [['name' => 'new', 'isCurrent' => true], ['name' => 'pending_payment'], ['name' => 'confirmed'], ['name' => 'cancelled']],
        'transitions' => [
            ['source' => 'new', 'target' => 'pending_payment', 'event' => 'place_order'],
            ['source' => 'pending_payment', 'target' => 'confirmed', 'event' => 'payment_success', 'command' => 'Experiment08\\Charge'],
            ['source' => 'pending_payment', 'target' => 'cancelled', 'event' => 'auto_cancel'],
        ],
        'events' => [['name' => 'place_order', 'manual' => true], ['name' => 'payment_success', 'manual' => true], ['name' => 'auto_cancel', 'timeout' => '30 minutes']],
    ];

    // No custom storage: the default StatefulStateStorage reads and writes the entity through StatefulInterface.
    return new StateMachineRunner((new DefinitionReader())->readArray($def), scheduler: $scheduler);
}

function rawState(string $db, string $id): ?string
{
    $v = (new PDO("sqlite:{$db}"))->query("SELECT state FROM orders WHERE id = '{$id}'")->fetchColumn();

    return $v === false ? null : $v;
}

function rawInt(string $db, string $sql): int
{
    return (int) (new PDO("sqlite:{$db}"))->query($sql)->fetchColumn();
}

echo "### A1 entity as subject: mapping, persistence boundary\n";
$db = freshDb();
$em = newEm($db);
$runner = machine(new DoctrineTimeoutScheduler($em));
$order = new OrderEntity('E-1');
$em->persist($order);
$em->flush();
$em->clear();
$order = $em->find(OrderEntity::class, 'E-1');
check('entity loaded by the ORM works as a subject (initial state null)', $order->getState() === null);
$runner->applyTo($order, 'place_order');
check('applyTo moves the entity in memory', $order->getState() === 'pending_payment');
check('...but the database still has the old state until flush()', rawState($db, 'E-1') === null, 'db state: ' . var_export(rawState($db, 'E-1'), true));
check('...while the timeout row is already written (scheduler bypasses the unit of work)', rawInt($db, "SELECT COUNT(*) FROM timeouts WHERE order_id = 'E-1'") === 1);
$em->flush();
check('flush() persists the new state', rawState($db, 'E-1') === 'pending_payment');
check('the ORM version column was incremented by the flush', $order->getVersion() === 2, 'version ' . $order->getVersion());

echo "\n### A2 failure between applyTo and flush\n";
$db = freshDb();
$em = newEm($db);
$runner = machine(new DoctrineTimeoutScheduler($em));
$order = new OrderEntity('E-2');
$em->persist($order);
$em->flush();
$runner->applyTo($order, 'place_order');
$em->flush();
// An exception (listener, guard, anything) between the state change and the flush:
$runner->applyTo($order, 'payment_success');
// ... exception thrown here in real code; the flush never happens
check('in-memory entity is confirmed', $order->getState() === 'confirmed');
check('database still says pending_payment', rawState($db, 'E-2') === 'pending_payment');
note('the managed entity now disagrees with the database; the next flush() would save it. After a failure the EntityManager must be cleared or closed, never reused.');
$em->clear();
check('after clear(), re-reading gives the stored state', $em->find(OrderEntity::class, 'E-2')->getState() === 'pending_payment');

echo "\n### A3 two requests on the same order: ORM optimistic locking against the experiment 1 race\n";
$db = freshDb();
$setup = newEm($db);
$o = new OrderEntity('E-3');
$setup->persist($o);
$setup->flush();
machine(new DoctrineTimeoutScheduler($setup))->applyTo($o, 'place_order');
$setup->flush();

$em1 = newEm($db);
$em2 = newEm($db);
$r1 = machine(new DoctrineTimeoutScheduler($em1));
$r2 = machine(new DoctrineTimeoutScheduler($em2));
$a = $em1->find(OrderEntity::class, 'E-3');   // timeout worker's request
$b = $em2->find(OrderEntity::class, 'E-3');   // payment webhook's request, loaded before the other one wrote
check('both requests loaded the same version', $a->getVersion() === $b->getVersion(), 'v' . $a->getVersion());
$r1->applyTo($a, 'auto_cancel');
$em1->flush();
Charge::$runs = 0;
$r2->applyTo($b, 'payment_success');
$conflict = null;
try {
    $em2->flush();
} catch (OptimisticLockException $e) {
    $conflict = $e;
}
check('the second flush is rejected with OptimisticLockException (no lost update)', $conflict !== null, $conflict ? get_class($conflict) : 'no exception');
check('database keeps the first writer\'s state', rawState($db, 'E-3') === 'cancelled');
check('BUT the payment command already ran before the flush failed', Charge::$runs === 1, 'charge runs: ' . Charge::$runs);
note('the optimistic lock protects the state, not side effects: commands and events run before flush(), so they must be deferred or idempotent (experiment 2).');
$em2->clear();
$b = $em2->find(OrderEntity::class, 'E-3');
$res = $r2->applyTo($b, 'payment_success');
check('retry after reload: late payment on a cancelled order is NoTransition (stays cancelled)', $res->getStatus()->name === 'NoTransition', $res->getStatus()->name);

echo "\n### A4 timeouts with an ORM subject\n";
// (a) the playground's SqliteTimeoutScheduler hands back a bare Order(id), not the entity.
$db = freshDb();
$timeoutDb = sys_get_temp_dir() . '/exp8-timeouts-' . getmypid() . '.sqlite';
@unlink($timeoutDb);
$em = newEm($db);
$runner = machine(new SqliteTimeoutScheduler($timeoutDb));
$order = new OrderEntity('E-4a');
$em->persist($order);
$em->flush();
$runner->applyTo($order, 'place_order');
$em->flush();
$results = $runner->processTimeouts((new DateTimeImmutable())->modify('+31 minutes'));
$left = (int) (new PDO("sqlite:{$timeoutDb}"))->query('SELECT COUNT(*) FROM scheduled_timeouts')->fetchColumn();
check('(a) stub-returning scheduler: the due timeout is silently not applied', $results === [] && $order->getState() === 'pending_payment', 'applied=' . count($results) . ', order=' . $order->getState());
check('(a) ...and its row is already gone, so it will never fire', $left === 0);
// (b) a scheduler that re-hydrates the managed entity.
$db = freshDb();
$em = newEm($db);
$runner = machine(new DoctrineTimeoutScheduler($em));
$order = new OrderEntity('E-4b');
$em->persist($order);
$em->flush();
$runner->applyTo($order, 'place_order');
$em->flush();
$results = $runner->processTimeouts((new DateTimeImmutable())->modify('+31 minutes'));
check('(b) re-hydrating scheduler: the timeout is applied to the managed entity', count($results) === 1 && $order->getState() === 'cancelled');
check('(b) ...but processTimeouts() does not flush: the database still says pending_payment', rawState($db, 'E-4b') === 'pending_payment');
$em->flush();
check('(b) ...the worker must flush (per item, handling conflicts)', rawState($db, 'E-4b') === 'cancelled');

echo "\n" . ($failures === 0 ? 'ALL CHECKS PASSED' : "{$failures} CHECK(S) FAILED") . "\n";
