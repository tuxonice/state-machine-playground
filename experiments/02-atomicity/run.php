<?php

declare(strict_types=1);

/**
 * Experiment 2: atomicity and failure.
 *
 * Injects a failure at each step of a transition (before-listener, command, storage write,
 * after-listener, scheduler, a later onEnter step) and records what is left behind: the state,
 * the side-effect ledger, the pending timeout, and the things that cannot be rolled back
 * (events seen, e-mails sent). Every scenario runs twice:
 *   raw: applyTo() as the library ships it
 *   tx:  the same call wrapped in one DB transaction (storage, scheduler and ledger share a connection)
 *
 * usage: php experiments/02-atomicity/run.php
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/support.php';

use Experiment02\Ctx;
use Experiment02\Dispatcher;
use Experiment02\PdoScheduler;
use Experiment02\PdoStorage;
use Tlab\StatemachinePlayground\Entity\Order;
use Tlab\StateMachine\Reader\DefinitionReader;
use Tlab\StateMachine\StateMachineRunner;

function runner(): StateMachineRunner
{
    return new StateMachineRunner(
        (new DefinitionReader())->readFile(__DIR__ . '/machine.json'),
        dispatcher: new Dispatcher(),
        storage: new PdoStorage(),
        scheduler: new PdoScheduler(),
    );
}

/** @return array{string|null, string|null} [exception, result summary] */
function attempt(string $mode, StateMachineRunner $runner, Order $order, string $event): array
{
    $pdo = Ctx::$pdo;
    try {
        if ($mode === 'tx') {
            $pdo->beginTransaction();
        }
        $result = $runner->applyTo($order, $event);
        if ($mode === 'tx') {
            $pdo->commit();
        }

        return [null, $result->getStatus()->name . ' -> ' . $result->getState()];
    } catch (Throwable $e) {
        if ($mode === 'tx' && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [$e->getMessage(), null];
    }
}

function snapshot(string $id): array
{
    $pdo = Ctx::$pdo;
    $state = $pdo->query("SELECT state FROM states WHERE id = '{$id}'")->fetchColumn();
    $effects = $pdo->query('SELECT what FROM effects')->fetchAll(PDO::FETCH_COLUMN);
    $timeouts = $pdo->query("SELECT event FROM timeouts WHERE order_id = '{$id}'")->fetchAll(PDO::FETCH_COLUMN);

    return [
        'state' => $state === false ? '(none)' : $state,
        'ledger' => $effects ?: ['-'],
        'timeout' => $timeouts ?: ['-'],
        'external' => Ctx::$external ?: ['-'],
    ];
}

/**
 * Each scenario: [title, setup (state to reach before arming faults), arm faults, event to apply].
 * $setup receives runner+order and applies events to reach the starting state.
 */
$scenarios = [
    'S1 before-listener throws' => [
        'setup' => fn($r, $o) => null,
        'arm' => function () { Ctx::$failBeforeListener = true; },
        'event' => 'place_order',
    ],
    'S2 command throws (before its effect)' => [
        'setup' => fn($r, $o) => $r->applyTo($o, 'place_order'),
        'arm' => function () { Ctx::$failCommand = 'charge'; },
        'event' => 'payment_success',
    ],
    'S3 storage write fails after command ran' => [
        'setup' => fn($r, $o) => $r->applyTo($o, 'place_order'),
        'arm' => function () { Ctx::$failWriteTo = 'confirmed'; },
        'event' => 'payment_success',
    ],
    'S4 after-listener throws (state already written)' => [
        'setup' => fn($r, $o) => null,
        'arm' => function () { Ctx::$failAfterListenerOn = 'pending_payment'; },
        'event' => 'place_order',
    ],
    'S5 scheduler fails after state write' => [
        'setup' => fn($r, $o) => null,
        'arm' => function () { Ctx::$failSchedule = true; },
        'event' => 'place_order',
    ],
    'S6 onEnter step fails (2nd step of a chain)' => [
        'setup' => fn($r, $o) => $r->applyTo($o, 'place_order'),
        'arm' => function () { Ctx::$failCommand = 'reserve'; },
        'event' => 'payment_success',
    ],
];

foreach ($scenarios as $title => $s) {
    echo "\n### {$title}\n";
    foreach (['raw', 'tx'] as $mode) {
        Ctx::reset();
        $runner = runner();
        $order = new Order('ORD-1');
        ($s['setup'])($runner, $order);
        Ctx::$external = []; // only observe the step under test
        $effectsBefore = Ctx::$pdo->query('SELECT COUNT(*) FROM effects')->fetchColumn();
        ($s['arm'])();

        [$error, $result] = attempt($mode, $runner, $order, $s['event']);
        $snap = snapshot('ORD-1');

        printf("  [%-3s] caller got: %s\n", $mode, $error !== null ? "EXCEPTION ({$error})" : $result);
        printf("        state=%s  ledger=%s  timeout=%s\n", $snap['state'], implode(',', $snap['ledger']), implode(',', $snap['timeout']));
        printf("        not rollbackable: %s\n", implode(' | ', $snap['external']));
    }
}
echo "\n";
