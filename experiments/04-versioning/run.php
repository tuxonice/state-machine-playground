<?php

declare(strict_types=1);

/**
 * Experiment 4: versioned, immutable definitions.
 *
 * usage: php experiments/04-versioning/run.php
 */

require __DIR__ . '/../../vendor/autoload.php';
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'Experiment04\\')) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 13)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Experiment04\Catalog;
use Experiment04\Fingerprints;
use Experiment04\OrderService;
use Experiment04\VersionedStore;
use Tlab\StatemachinePlayground\Entity\Order;
use Tlab\StatemachinePlayground\Storage\SqliteStateStorage;
use Tlab\StateMachine\Exceptions\MachineRegistryException;
use Tlab\StateMachine\Exceptions\UnknownEventException;
use Tlab\StateMachine\Reader\DefinitionReader;
use Tlab\StateMachine\Registry\StateMachineRegistry;
use Tlab\StateMachine\Scheduler\InMemoryTimeoutScheduler;
use Tlab\StateMachine\StateMachineRunner;

$failures = 0;
function check(string $title, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  PASS ' : '  FAIL ') . $title . ($detail !== '' ? "  [{$detail}]" : '') . "\n";
    $failures += $ok ? 0 : 1;
}

$dir = __DIR__ . '/definitions';

echo "### C1 registry with two versions that share the same internal name\n";
$reader = new DefinitionReader();
$plain = new StateMachineRegistry();
$plain->register(new StateMachineRunner($reader->readFile("{$dir}/order-v1.json")));
try {
    $plain->register(new StateMachineRunner($reader->readFile("{$dir}/order-v2.json")));
    check('registering v2 under its default name is rejected', false);
} catch (MachineRegistryException $e) {
    check('registering v2 under its default name is rejected', true, $e->getMessage());
}
$store = new VersionedStore();
$scheduler = new InMemoryTimeoutScheduler();
$catalog = new Catalog($dir, 'order', $store, $scheduler);
check('Catalog registers both under explicit keys', $catalog->registry->names() === ['order@v1', 'order@v2'], implode(',', $catalog->registry->names()));

echo "\n### C2 deploy of v2 while v1 orders are in flight\n";
$before = new Catalog($dir, 'order', $store, $scheduler, 1);   // what production runs before the release
$svc = new OrderService($before, $store);
foreach (['A', 'B'] as $id) {
    $svc->create($id);
    $svc->apply($id, 'place_order');
}
$svc->apply('A', 'payment_success', ['payment_method' => 'card']);
// Release: the process now knows v1 and v2.
$svc = new OrderService($catalog, $store);
$vC = $svc->create('C');
$svc->apply('C', 'place_order');
check('new order C starts on the latest version', $vC === 2, "v{$vC}");
check('in-flight order A stays on v1', $store->versionOf('A') === 1);
check('A (confirmed, v1) offers v1 events only', $svc->availableEvents('A') === ['ship'], implode(',', $svc->availableEvents('A')));
$svc->apply('C', 'payment_success', ['payment_method' => 'card', 'address' => 'Somewhere 1']);
check('C (v2) pays into awaiting_review', $store->distribution()[2]['awaiting_review'] === 1);
check('C (v2) offers the v2 review events', $svc->availableEvents('C') === ['approve_review', 'reject_review'], implode(',', $svc->availableEvents('C')));
$svc->apply('A', 'ship');
$svc->apply('A', 'deliver');
check('A finishes its v1 flow after v2 is live', $store->distribution()[1]['delivered'] === 1);
$due = [];
foreach ($scheduler->all() as $t) {
    $due[$t->getSubject()->getId()] = $t->getDueAt()->getTimestamp();
}
check('timeouts follow each order\'s own version (B v1 30 min)', isset($due['B']) && abs($due['B'] - (time() + 1800)) < 5, 'B due in ' . ($due['B'] - time()) . 's');

echo "\n### C3 v2-only event on a v1 order\n";
try {
    $svc->apply('B', 'approve_review');
    check('v1 order rejects a v2-only event', false);
} catch (UnknownEventException $e) {
    check('v1 order rejects a v2-only event loudly', true, $e->getMessage());
}
$svc->apply('B', 'payment_success', ['payment_method' => 'card']);
check('v1 guard (payment only) lets B through without an address', $store->distribution()[1]['confirmed'] === 1);

echo "\n### C4 retirement: orders still active per version\n";
$active = function () use ($store, $catalog): array {
    $out = [];
    foreach ($catalog->versions() as $v) {
        $terminal = $catalog->terminalStates($v);
        $out[$v] = array_sum(array_map(
            fn($state, $n) => in_array($state, $terminal, true) ? 0 : $n,
            array_keys($store->distribution()[$v] ?? []),
            $store->distribution()[$v] ?? []
        ));
    }

    return $out;
};
echo '  terminal states v1: ' . implode(',', $catalog->terminalStates(1)) . ' | v2: ' . implode(',', $catalog->terminalStates(2)) . "\n";
echo '  active orders: ' . json_encode($active()) . "\n";
check('v1 not retirable while B is active', $active()[1] === 1);
$svc->apply('B', 'ship');
$svc->apply('B', 'deliver');
check('v1 retirable once every v1 order reached a terminal state', $active()[1] === 0, json_encode($active()));
// Retiring early: a runner map without v1, while an order is still on it.
$svc->create('D'); // v2, unaffected
$earlyRegistry = new StateMachineRegistry();
$earlyRegistry->register(new StateMachineRunner($reader->readFile("{$dir}/order-v2.json"), storage: $store), 'order@v2');
try {
    $earlyRegistry->get('order@v1');
    check('retired version fails loudly if an order still points at it', false);
} catch (MachineRegistryException $e) {
    check('retired version fails loudly if an order still points at it', true, $e->getMessage());
}

echo "\n### C5 release guardrail (definition + referenced class fingerprints)\n";
$released = Fingerprints::build($catalog);
check('untouched repo matches the released fingerprints', Fingerprints::diff($released, Fingerprints::build($catalog)) === []);

$tmp = sys_get_temp_dir() . '/exp4-' . getmypid();
mkdir($tmp);
// (a) someone edits the released v1 JSON
$copy = "{$tmp}/definitions";
mkdir($copy);
foreach (glob("{$dir}/*.json") as $f) {
    copy($f, "{$copy}/" . basename($f));
}
file_put_contents("{$copy}/order-v1.json", str_replace('30 minutes', '45 minutes', file_get_contents("{$copy}/order-v1.json")));
$tampered = new Catalog($copy, 'order', $store, $scheduler);
$diff = Fingerprints::diff($released, Fingerprints::build($tampered, null));
check('editing released order-v1.json is detected', in_array('v1: definition changed after release', $diff, true), implode('; ', $diff));
// (b) someone edits the shared guard class that v1 depends on
$guardCopy = "{$tmp}/HasPayment.php";
file_put_contents($guardCopy, file_get_contents(__DIR__ . '/src/Guards/HasPayment.php') . "\n// changed\n");
$diff = Fingerprints::diff($released, Fingerprints::build($catalog, fn(string $c) => $c === 'Experiment04\\Guards\\HasPayment' ? $guardCopy : (new ReflectionClass($c))->getFileName()));
check('editing the shared HasPayment guard is detected for v1', in_array('v1: Experiment04\\Guards\\HasPayment changed after release', $diff, true), implode('; ', $diff));
check('...and v2 (which uses a new guard class) is unaffected', array_filter($diff, fn($d) => str_starts_with($d, 'v2:')) === []);
// (c) adding a v3 file does not disturb v1/v2
copy("{$dir}/order-v2.json", "{$copy}/order-v3.json");
copy("{$dir}/order-v1.json", "{$copy}/order-v1.json"); // restore the v1 file edited in (a)
$withV3 = new Catalog($copy, 'order', $store, $scheduler);
$current = Fingerprints::build($withV3);
$diffV3 = Fingerprints::diff($released, $current);
check('a v3 file is picked up by the catalog', $withV3->versions() === [1, 2, 3], implode(',', $withV3->versions()));
check('adding a new version file leaves released fingerprints intact', $diffV3 === [], implode('; ', $diffV3));

echo "\n### C6 existing SqliteStateStorage: is state scoped by machine name?\n";
$db = $tmp . '/scope.sqlite';
$orderStore = new SqliteStateStorage($db, 'order@v1');
$leadStore = new SqliteStateStorage($db, 'order@v2');
$orderStore->write(new Order('SAME-ID'), 'confirmed');
$seenByV2 = $leadStore->read(new Order('SAME-ID'));
echo '  FINDING: ' . ($seenByV2 === null
    ? 'state is scoped by machine name'
    : "state written under machine 'order@v1' is visible when read under 'order@v2' (read: '{$seenByV2}'); only listOrders() filters by machine") . "\n";

echo "\n" . ($failures === 0 ? 'ALL CHECKS PASSED' : "{$failures} CHECK(S) FAILED") . "\n";
