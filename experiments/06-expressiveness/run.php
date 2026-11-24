<?php

declare(strict_types=1);

/**
 * Experiment 6: expressiveness limits.
 *
 * X1 wildcard / list sources, X2 compound conditions
 * X3 self-transitions and onEnter self-loops
 * X4 two timeouts in one state (reminder + cancel)
 * X5 parallel tracks: product state machine vs coordinated machines
 * X6 pause / resume back to the previous state
 *
 * usage: php experiments/06-expressiveness/run.php
 */

namespace {
    require __DIR__ . '/../../vendor/autoload.php';
}

namespace Experiment06 {

    use DateTimeImmutable;
    use Psr\EventDispatcher\EventDispatcherInterface;
    use Tlab\StateMachine\Commands\CommandInterface;
    use Tlab\StateMachine\Conditions\ConditionInterface;
    use Tlab\StateMachine\Events\AfterTransition;
    use Tlab\StateMachine\Scheduler\InMemoryTimeoutScheduler;
    use Tlab\StateMachine\Scheduler\ScheduledTimeout;
    use Tlab\StateMachine\Scheduler\TimeoutScheduler;

    final class Counter implements CommandInterface
    {
        public static int $runs = 0;

        public function run(array $data): void
        {
            self::$runs++;
        }
    }

    final class BothDone implements ConditionInterface
    {
        public function check(array $data): bool
        {
            return ($data['payment'] ?? null) === 'paid' && ($data['shipment'] ?? null) === 'delivered';
        }
    }

    final class PrevIsA implements ConditionInterface
    {
        public function check(array $data): bool
        {
            return ($data['previous'] ?? null) === 'a';
        }
    }

    final class PrevIsB implements ConditionInterface
    {
        public function check(array $data): bool
        {
            return ($data['previous'] ?? null) === 'b';
        }
    }

    final class PrevIsC implements ConditionInterface
    {
        public function check(array $data): bool
        {
            return ($data['previous'] ?? null) === 'c';
        }
    }

    /**
     * The runner stamps timeouts with the system clock and has no clock to inject. This decorator maps
     * "real now + delay" onto a simulated clock, so timers can be walked through without sleeping.
     */
    final class SimClockScheduler implements TimeoutScheduler
    {
        public DateTimeImmutable $simNow;
        private InMemoryTimeoutScheduler $inner;

        public function __construct()
        {
            $this->simNow = new DateTimeImmutable('2030-01-01 00:00:00');
            $this->inner = new InMemoryTimeoutScheduler();
        }

        public function schedule(object $subject, string $state, string $event, DateTimeImmutable $dueAt): void
        {
            $delay = $dueAt->getTimestamp() - time();
            $this->inner->schedule($subject, $state, $event, $this->simNow->modify("+{$delay} seconds"));
        }

        public function cancel(object $subject): void
        {
            $this->inner->cancel($subject);
        }

        public function pullDue(DateTimeImmutable $now): array
        {
            return $this->inner->pullDue($now);
        }

        /** @return ScheduledTimeout[] */
        public function all(): array
        {
            return $this->inner->all();
        }
    }

    /** Tells children's AfterTransition events to a callback: the "join" the model has no syntax for. */
    final class Hook implements EventDispatcherInterface
    {
        /** @var callable */
        public $onAfter;

        public function dispatch(object $event): object
        {
            if ($event instanceof AfterTransition && $this->onAfter) {
                ($this->onAfter)($event);
            }

            return $event;
        }
    }
}

namespace {

    use Experiment06\BothDone;
    use Experiment06\Counter;
    use Experiment06\Hook;
    use Experiment06\SimClockScheduler;
    use Tlab\StatemachinePlayground\Entity\Order;
    use Tlab\StateMachine\Exceptions\OnEnterLoopException;
    use Tlab\StateMachine\Reader\DefinitionReader;
    use Tlab\StateMachine\Registry\StateMachineRegistry;
    use Tlab\StateMachine\StateMachineRunner;

    function show(string $title, string $detail): void
    {
        echo "  {$title}: {$detail}\n";
    }

    function def(string $name, array $states, array $transitions, array $events): array
    {
        return [
            'name' => $name,
            'states' => array_map(fn($s, $i) => ['name' => $s] + ($i === 0 ? ['isCurrent' => true] : []), $states, array_keys($states)),
            'transitions' => $transitions,
            'events' => $events,
        ];
    }

    function tryLoad(array $def): string
    {
        try {
            (new DefinitionReader())->readArray($def);

            return 'ACCEPTED';
        } catch (Throwable $e) {
            return 'REJECTED (' . (new ReflectionClass($e))->getShortName() . ': ' . substr($e->getMessage(), 0, 100) . ')';
        }
    }

    $C = 'Experiment06\\';

    echo "### X1 \"cancel from any state\": wildcard or list as source\n";
    $states = ['a', 'b', 'c', 'cancelled'];
    $ev = [['name' => 'cancel', 'manual' => true]];
    show('source "*"', tryLoad(def('x', $states, [['source' => '*', 'target' => 'cancelled', 'event' => 'cancel']], $ev)));
    show('source ["a","b","c"]', tryLoad(def('x', $states, [['source' => ['a', 'b', 'c'], 'target' => 'cancelled', 'event' => 'cancel']], $ev)));
    $explicit = array_map(fn($s) => ['source' => $s, 'target' => 'cancelled', 'event' => 'cancel'], ['a', 'b', 'c']);
    show('explicit, one transition per source', tryLoad(def('x', $states, $explicit, $ev)) . ' -> ' . count($explicit) . ' transitions for 3 sources');

    echo "\n### X2 compound condition on one transition\n";
    $t = ['source' => 'a', 'target' => 'b', 'event' => 'go'];
    $ev = [['name' => 'go', 'manual' => true]];
    show('"condition": ["A","B"] (AND)', tryLoad(def('x', ['a', 'b'], [$t + ['condition' => [$C . 'BothDone', $C . 'PrevIsA']]], $ev)));
    show('"condition": "A && B"', tryLoad(def('x', ['a', 'b'], [$t + ['condition' => $C . 'BothDone && ' . $C . 'PrevIsA']], $ev)));
    $r = new StateMachineRunner((new DefinitionReader())->readArray(def('x', ['a', 'b'], [$t + ['condition' => $C . 'BothDone && ' . $C . 'PrevIsA']], $ev)));
    try {
        $r->apply('a', 'go');
        show('"A && B" as a class name, when used', 'no error');
    } catch (Throwable $e) {
        show('"A && B" as a class name, when used', (new ReflectionClass($e))->getShortName() . ': ' . $e->getMessage());
    }
    show('workaround', 'a composite guard class per combination (no way to pass arguments from the JSON)');

    echo "\n### X3 self-transitions\n";
    $sched = new SimClockScheduler();
    $selfDef = def('self', ['s'], [['source' => 's', 'target' => 's', 'event' => 'tick', 'command' => $C . 'Counter']], [
        ['name' => 'tick', 'manual' => true],
    ]);
    $runner = new StateMachineRunner((new DefinitionReader())->readArray($selfDef));
    $o = new Order('SELF-1');
    $o->setState('s');
    $res = $runner->applyTo($o, 'tick');
    show('manual self-loop s -> s', "status={$res->getStatus()->name}, state={$res->getState()}, command runs=" . Counter::$runs);

    $loopDef = def('loop', ['s'], [['source' => 's', 'target' => 's', 'event' => 'spin', 'command' => $C . 'Counter']], [
        ['name' => 'spin', 'onEnter' => true], ['name' => 'kick', 'manual' => true],
    ]);
    $loopDef['transitions'][] = ['source' => 's', 'target' => 's', 'event' => 'kick'];
    Counter::$runs = 0;
    $o = new Order('LOOP-1');
    $o->setState('s');
    try {
        (new StateMachineRunner((new DefinitionReader())->readArray($loopDef)))->applyTo($o, 'kick');
        show('onEnter self-loop', 'no error');
    } catch (OnEnterLoopException $e) {
        show('onEnter self-loop', 'OnEnterLoopException after ' . Counter::$runs . ' command runs: ' . $e->getMessage());
    }

    echo "\n### X4 two timeouts in one state: reminder at 15 min, cancel at 30 min\n";
    $timeline = function (array $def, string $subjectId, array $minutes, SimClockScheduler $sched, StateMachineRunner $runner): array {
        $o = new Order($subjectId);
        $runner->applyTo($o, 'create');
        $log = [];
        foreach ($minutes as $m) {
            $sched->simNow = (new DateTimeImmutable('2030-01-01 00:00:00'))->modify("+{$m} minutes");
            $before = $o->getState();
            $runner->processTimeouts($sched->simNow);
            $log[] = "t={$m}m " . ($before === $o->getState() ? "{$before} (reminders so far: " . Counter::$runs . ')' : "{$before}->{$o->getState()}");
        }

        return $log;
    };
    // (a) reminder as a self-loop on the same state
    $a = def('rem', ['new', 'pending', 'cancelled'], [
        ['source' => 'new', 'target' => 'pending', 'event' => 'create'],
        ['source' => 'pending', 'target' => 'pending', 'event' => 'remind', 'command' => $C . 'Counter'],
        ['source' => 'pending', 'target' => 'cancelled', 'event' => 'auto_cancel'],
    ], [['name' => 'create', 'manual' => true], ['name' => 'remind', 'timeout' => '15 minutes'], ['name' => 'auto_cancel', 'timeout' => '30 minutes']]);
    Counter::$runs = 0;
    $sched = new SimClockScheduler();
    $runner = new StateMachineRunner((new DefinitionReader())->readArray($a), scheduler: $sched);
    show('(a) reminder = self-loop on pending', implode(' | ', $timeline($a, 'T-A', [16, 32, 48, 64, 80, 96], $sched, $runner)));

    // (b) reminder as its own state
    $b = def('rem2', ['new', 'pending', 'reminded', 'cancelled'], [
        ['source' => 'new', 'target' => 'pending', 'event' => 'create'],
        ['source' => 'pending', 'target' => 'reminded', 'event' => 'remind', 'command' => $C . 'Counter'],
        ['source' => 'reminded', 'target' => 'cancelled', 'event' => 'auto_cancel'],
    ], [['name' => 'create', 'manual' => true], ['name' => 'remind', 'timeout' => '15 minutes'], ['name' => 'auto_cancel', 'timeout' => '15 minutes']]);
    Counter::$runs = 0;
    $sched = new SimClockScheduler();
    $runner = new StateMachineRunner((new DefinitionReader())->readArray($b), scheduler: $sched);
    show('(b) reminder = separate state (pending -15m-> reminded -15m-> cancelled)', implode(' | ', $timeline($b, 'T-B', [16, 31, 32], $sched, $runner)));
    show('timeout clock', 'measured from the moment the state is entered, and from when the worker processed the previous one (lateness adds up); no absolute deadline');

    echo "\n### X5 parallel tracks: payment and shipment run independently, order completes when both are done\n";
    $payStates = ['unpaid', 'authorized', 'paid', 'refunded'];
    $shipStates = ['not_shipped', 'packed', 'shipped', 'delivered', 'returned'];
    $payT = [['unpaid', 'authorized'], ['authorized', 'paid'], ['paid', 'refunded']];
    $shipT = [['not_shipped', 'packed'], ['packed', 'shipped'], ['shipped', 'delivered'], ['delivered', 'returned']];
    $productStates = [];
    foreach ($payStates as $p) {
        foreach ($shipStates as $s) {
            $productStates[] = "{$p}__{$s}";
        }
    }
    $productT = [];
    foreach ($payT as [$from, $to]) {
        foreach ($shipStates as $s) {
            $productT[] = ['source' => "{$from}__{$s}", 'target' => "{$to}__{$s}", 'event' => "pay_{$from}_{$to}"];
        }
    }
    foreach ($shipT as [$from, $to]) {
        foreach ($payStates as $p) {
            $productT[] = ['source' => "{$p}__{$from}", 'target' => "{$p}__{$to}", 'event' => "ship_{$from}_{$to}"];
        }
    }
    show('one machine = product of both tracks', count($productStates) . ' states and ' . count($productT) . ' transitions (vs ' . (count($payStates) + count($shipStates)) . ' states and ' . (count($payT) + count($shipT)) . ' transitions as two machines)');

    $registry = new StateMachineRegistry();
    $hook = new Hook();
    $registry->register(new StateMachineRunner((new DefinitionReader())->readArray(def('payment', ['unpaid', 'paid'], [['source' => 'unpaid', 'target' => 'paid', 'event' => 'capture']], [['name' => 'capture', 'manual' => true]])), dispatcher: $hook));
    $registry->register(new StateMachineRunner((new DefinitionReader())->readArray(def('shipment', ['waiting', 'shipped', 'delivered'], [['source' => 'waiting', 'target' => 'shipped', 'event' => 'ship'], ['source' => 'shipped', 'target' => 'delivered', 'event' => 'deliver']], [['name' => 'ship', 'manual' => true], ['name' => 'deliver', 'manual' => true]])), dispatcher: $hook));
    $registry->register(new StateMachineRunner((new DefinitionReader())->readArray(def('order', ['open', 'completed'], [['source' => 'open', 'target' => 'completed', 'event' => 'complete', 'condition' => $C . 'BothDone']], [['name' => 'complete', 'manual' => true]]))));

    $run = function (array $sequence) use ($registry, $hook): string {
        $order = new Order('X');
        $order->setState('open');
        $pay = new Order('X:payment');
        $ship = new Order('X:shipment');
        $completions = 0;
        // The join: after any child transition, ask the parent. The parent's guard checks both states from $data.
        $hook->onAfter = function ($event) use ($registry, $order, $pay, $ship, &$completions) {
            $res = $registry->get('order')->applyTo($order, 'complete', ['payment' => $pay->getState(), 'shipment' => $ship->getState()]);
            $completions += $res->hasMoved() ? 1 : 0;
        };
        $log = [];
        foreach ($sequence as [$machine, $subjectEvent]) {
            $subject = $machine === 'payment' ? $pay : $ship;
            $registry->get($machine)->applyTo($subject, $subjectEvent);
            $log[] = "{$machine}:{$subjectEvent}->order={$order->getState()}";
        }

        return implode(', ', $log) . "  (completions: {$completions})";
    };
    show('coordinated machines, pay last', $run([['shipment', 'ship'], ['shipment', 'deliver'], ['payment', 'capture']]));
    show('coordinated machines, pay first', $run([['payment', 'capture'], ['shipment', 'ship'], ['shipment', 'deliver']]));
    show('coordinated machines, never paid', $run([['shipment', 'ship'], ['shipment', 'deliver']]));
    show('what the library gives for the join', 'nothing: no fork/join syntax, a parent guard cannot read children (no subject), so the app listens to AfterTransition and passes the children\'s states in $data');

    echo "\n### X6 pause, then resume to wherever the order came from\n";
    $pr = def('pause', ['a', 'b', 'c', 'hold'], [
        ['source' => 'a', 'target' => 'hold', 'event' => 'pause'],
        ['source' => 'b', 'target' => 'hold', 'event' => 'pause'],
        ['source' => 'c', 'target' => 'hold', 'event' => 'pause'],
        ['source' => 'hold', 'target' => 'a', 'event' => 'resume', 'condition' => $C . 'PrevIsA'],
        ['source' => 'hold', 'target' => 'b', 'event' => 'resume', 'condition' => $C . 'PrevIsB'],
        ['source' => 'hold', 'target' => 'c', 'event' => 'resume', 'condition' => $C . 'PrevIsC'],
    ], [['name' => 'pause', 'manual' => true], ['name' => 'resume', 'manual' => true]]);
    $runner = new StateMachineRunner((new DefinitionReader())->readArray($pr));
    foreach (['a', 'b', 'c'] as $from) {
        $o = new Order("P-{$from}");
        $o->setState($from);
        $runner->applyTo($o, 'pause');
        $runner->applyTo($o, 'resume', ['previous' => $from]);
        show("pause from {$from}, resume with previous={$from}", "back in {$o->getState()}");
    }
    show('cost', '1 pause transition and 1 guarded resume transition per origin state, and 1 guard class per origin (guards cannot be parameterised from the JSON); the caller must remember the previous state itself');
    echo "\n";
}
