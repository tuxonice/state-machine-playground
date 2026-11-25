<?php

declare(strict_types=1);

/**
 * Experiment 5: real dependencies in guards and commands.
 *
 * D1 container wiring and what happens when it is incomplete
 * D2 what a guard / command / listener can see (subject? actor? data?)
 * D3 permission guards and what the "available events" listings do with them
 * D4 how often a service-backed guard is called
 * D5 commands with injected services
 * D6 branching on one event by a service-backed guard
 *
 * usage: php experiments/05-dependencies/run.php
 */

namespace {
    require __DIR__ . '/../../vendor/autoload.php';
}

namespace Experiment05 {

    use Psr\Container\ContainerInterface;
    use Psr\Container\NotFoundExceptionInterface;
    use Psr\EventDispatcher\EventDispatcherInterface;
    use Tlab\StateMachine\Commands\CommandInterface;
    use Tlab\StateMachine\Conditions\ConditionInterface;
    use Tlab\StateMachine\Events\AfterTransition;
    use Tlab\StateMachine\Events\BeforeTransition;
    use Tlab\StateMachine\Events\TransitionBlocked;
    use Tlab\StateMachine\TransitionContext;

    /** Minimal PSR-11 container: has() only for what was registered. */
    final class Container implements ContainerInterface
    {
        /** @param array<string, callable> $factories */
        public function __construct(private array $factories)
        {
        }

        public function has(string $id): bool
        {
            return isset($this->factories[$id]);
        }

        public function get(string $id): mixed
        {
            return isset($this->factories[$id])
                ? ($this->factories[$id])()
                : throw new class("no entry {$id}") extends \RuntimeException implements NotFoundExceptionInterface {};
        }
    }

    // ---- services (stand-ins for a stock API, a fraud API, a mailer) ----
    final class StockService
    {
        public int $calls = 0;

        public function inStock(string $orderId): bool
        {
            $this->calls++;

            return true;
        }
    }

    final class FraudService
    {
        public function score(string $orderId): int
        {
            return str_starts_with($orderId, 'RISKY') ? 90 : 10;
        }
    }

    final class Mailer
    {
        /** @var list<string> */
        public array $sent = [];
    }

    // ---- guards and commands ----
    final class InStock implements ConditionInterface
    {
        public function __construct(private StockService $stock)
        {
        }

        public function check(TransitionContext $context): bool
        {
            return $this->stock->inStock($context->data['orderId']);
        }
    }

    final class LowRisk implements ConditionInterface
    {
        public function __construct(private FraudService $fraud)
        {
        }

        public function check(TransitionContext $context): bool
        {
            return $this->fraud->score($context->data['orderId']) < 50;
        }
    }

    final class IsManager implements ConditionInterface
    {
        public function check(TransitionContext $context): bool
        {
            return in_array('manager', $context->data['actor']['roles'] ?? [], true);
        }
    }

    /** Needs a constructor argument that nobody registered in the container. */
    final class NeedsUnregisteredService implements ConditionInterface
    {
        public function __construct(private StockService $stock)
        {
        }

        public function check(TransitionContext $context): bool
        {
            return true;
        }
    }

    final class NotACondition
    {
    }

    final class Spy implements ConditionInterface, CommandInterface
    {
        /** @var array<string, array<mixed>> */
        public static array $seen = [];

        public function check(TransitionContext $context): bool
        {
            self::$seen['guard'] = $context->data;
            self::$seen['guardSubject'] = $context->subject;

            return true;
        }

        public function run(TransitionContext $context): void
        {
            self::$seen['command'] = $context->data;
            self::$seen['commandSubject'] = $context->subject;
        }
    }

    final class NotifyCommand implements CommandInterface
    {
        public function __construct(private Mailer $mailer)
        {
        }

        public function run(TransitionContext $context): void
        {
            $this->mailer->sent[] = "refund mail for {$context->data['orderId']} approved by {$context->data['actor']['name']}";
        }
    }

    final class Listener implements EventDispatcherInterface
    {
        /** @var list<object> */
        public array $events = [];

        public function dispatch(object $event): object
        {
            $this->events[] = $event;

            return $event;
        }
    }
}

namespace {

    use Experiment05\Container;
    use Experiment05\FraudService;
    use Experiment05\Listener;
    use Experiment05\Mailer;
    use Experiment05\Spy;
    use Experiment05\StockService;
    use Tlab\StatemachinePlayground\Entity\Order;
    use Tlab\StateMachine\Events\AfterTransition;
    use Tlab\StateMachine\Events\BeforeTransition;
    use Tlab\StateMachine\Events\TransitionBlocked;
    use Tlab\StateMachine\Reader\DefinitionReader;
    use Tlab\StateMachine\Resolver\CommandResolver;
    use Tlab\StateMachine\Resolver\ConditionResolver;
    use Tlab\StateMachine\StateMachineRunner;

    function show(string $title, string $detail): void
    {
        echo "  {$title}: {$detail}\n";
    }

    function definition(array $overrides = []): array
    {
        $ns = 'Experiment05\\';

        return array_replace_recursive([
            'name' => 'exp5',
            'states' => [
                ['name' => 'new', 'isCurrent' => true], ['name' => 'confirmed'], ['name' => 'manual_review'],
                ['name' => 'packed'], ['name' => 'shipped'], ['name' => 'refunded'],
            ],
            'transitions' => [
                ['source' => 'new', 'target' => 'confirmed', 'event' => 'pay', 'condition' => $ns . 'LowRisk'],
                ['source' => 'new', 'target' => 'manual_review', 'event' => 'pay'],
                ['source' => 'confirmed', 'target' => 'packed', 'event' => 'auto_pack', 'condition' => $ns . 'InStock'],
                ['source' => 'confirmed', 'target' => 'shipped', 'event' => 'ship', 'condition' => $ns . 'InStock'],
                ['source' => 'confirmed', 'target' => 'refunded', 'event' => 'refund', 'condition' => $ns . 'IsManager', 'command' => $ns . 'NotifyCommand'],
            ],
            'events' => [
                ['name' => 'pay', 'manual' => true], ['name' => 'ship', 'manual' => true],
                ['name' => 'refund', 'manual' => true], ['name' => 'auto_pack', 'onEnter' => true],
            ],
        ], $overrides);
    }

    function runnerFor(array $def, ?Container $container, ?Listener $listener = null): StateMachineRunner
    {
        return new StateMachineRunner(
            (new DefinitionReader())->readArray($def),
            $container ? new ConditionResolver($container) : null,
            $container ? new CommandResolver($container) : null,
            $listener,
        );
    }

    $stock = new StockService();
    $fraud = new FraudService();
    $mailer = new Mailer();
    $container = new Container([
        'Experiment05\\InStock' => fn() => new Experiment05\InStock($stock),
        'Experiment05\\LowRisk' => fn() => new Experiment05\LowRisk($fraud),
        'Experiment05\\NotifyCommand' => fn() => new Experiment05\NotifyCommand($mailer),
    ]);

    echo "### D1 container wiring and incomplete wiring\n";
    $orderA = new Order('A-1');
    $r = runnerFor(definition(), $container);
    $res = $r->applyTo($orderA, 'pay', ['orderId' => 'A-1']);
    show('guard with injected FraudService', "pay -> {$res->getState()} (events: " . implode(',', $res->getEvents()) . "), stock calls={$stock->calls}");

    $broken = definition(['transitions' => [0 => ['condition' => 'Experiment05\\NeedsUnregisteredService']]]);
    try {
        runnerFor($broken, $container)->applyTo(new Order('A-2'), 'pay', ['orderId' => 'A-2']);
        show('guard needing an unregistered service', 'no error');
    } catch (Throwable $e) {
        show('guard needing an unregistered service', get_class($e) . ': ' . substr($e->getMessage(), 0, 90));
    }
    foreach (['Experiment05\\DoesNotExist' => 'missing class', 'Experiment05\\NotACondition' => 'wrong type'] as $class => $label) {
        $def = definition(['transitions' => [0 => ['condition' => $class]]]);
        try {
            $loaded = runnerFor($def, $container);
            show("{$label}: config loads", 'yes, no error at load time');
            $loaded->applyTo(new Order('A-3'), 'pay', ['orderId' => 'A-3']);
        } catch (Throwable $e) {
            show("{$label}: fails when first evaluated", get_class($e) . ': ' . $e->getMessage());
        }
    }

    echo "\n### D2 what guards, commands and listeners can see\n";
    $spyDef = definition([
        'transitions' => [0 => ['condition' => 'Experiment05\\Spy', 'command' => 'Experiment05\\Spy']],
    ]);
    $listener = new Listener();
    $data = ['orderId' => 'S-1', 'actor' => ['id' => 7, 'name' => 'Ana', 'roles' => ['clerk']]];
    runnerFor($spyDef, $container, $listener)->applyTo(new Order('S-1'), 'pay', $data);
    show('guard received the caller\'s $data unchanged', var_export(Spy::$seen['guard'] === $data, true));
    show('command received the caller\'s $data unchanged', var_export(Spy::$seen['command'] === $data, true));
    $after = array_values(array_filter($listener->events, fn($e) => $e instanceof AfterTransition))[0];
    show('AfterTransition carries $data and the subject', 'data same=' . var_export($after->data === $data, true) . ', subject=' . get_class($after->subject) . ' ' . $after->subject->getId());
    $sig = fn(string $class, string $method) => implode(', ', array_map(fn($p) => $p->getType() . ' $' . $p->getName(), (new ReflectionMethod($class, $method))->getParameters()));
    show('ConditionInterface::check signature', '(' . $sig(Tlab\StateMachine\Conditions\ConditionInterface::class, 'check') . ')');
    show('CommandInterface::run signature', '(' . $sig(Tlab\StateMachine\Commands\CommandInterface::class, 'run') . ')');
    $describe = fn(?object $o) => $o === null ? 'none' : get_class($o) . ' ' . $o->getId();
    show('subject seen by the guard', $describe(Spy::$seen['guardSubject']));
    show('subject seen by the command', $describe(Spy::$seen['commandSubject']));

    echo "\n### D3 permission guard and the \"available events\" listings\n";
    $clerk = ['orderId' => 'P-1', 'actor' => ['name' => 'Ana', 'roles' => ['clerk']]];
    $manager = ['orderId' => 'P-1', 'actor' => ['name' => 'Max', 'roles' => ['manager']]];
    $perm = runnerFor(definition(), $container);
    $o = new Order('P-1');
    $perm->applyTo($o, 'pay', $clerk);              // new -> confirmed (auto_pack runs too, see D4)
    // put it back to a plain confirmed order for the listing test
    $o2 = new Order('P-2');
    $o2->setState('confirmed');
    show('availableEventsFor(confirmed order)', implode(',', $perm->availableEventsFor($o2)) . '   (guards NOT evaluated, same for clerk and manager)');
    show('availableManualEventsFor', implode(',', $perm->availableManualEventsFor($o2)));
    $filtered = fn(array $d) => implode(',', array_filter($perm->availableManualEventsFor($o2), fn($e) => $perm->canApplyTo($o2, $e, $d)));
    show('manual events filtered with canApplyTo, clerk', $filtered($clerk));
    show('manual events filtered with canApplyTo, manager', $filtered($manager));
    show('allowedManualEventsFor, clerk', implode(',', $perm->allowedManualEventsFor($o2, $clerk)));
    show('allowedManualEventsFor, manager', implode(',', $perm->allowedManualEventsFor($o2, $manager)));
    $blockedListener = new Listener();
    $res = runnerFor(definition(), $container, $blockedListener)->applyTo($o2, 'refund', $clerk);
    $blocked = array_values(array_filter($blockedListener->events, fn($e) => $e instanceof TransitionBlocked));
    show('clerk applies refund', "status={$res->getStatus()->name}, state stays {$o2->getState()}, TransitionBlocked dispatched=" . count($blocked));
    $res = runnerFor(definition(), $container)->applyTo($o2, 'refund', $manager);
    show('manager applies refund', "status={$res->getStatus()->name}, state={$o2->getState()}");

    echo "\n### D4 how often a service-backed guard runs\n";
    $stock->calls = 0;
    $o3 = new Order('C-1');
    $o3->setState('confirmed');
    $r4 = runnerFor(definition(), $container);
    $r4->availableEventsFor($o3);
    $c1 = $stock->calls;
    $r4->canApplyTo($o3, 'ship', ['orderId' => 'C-1']);
    $c2 = $stock->calls;
    $r4->applyTo($o3, 'ship', ['orderId' => 'C-1']);
    $c3 = $stock->calls;
    show('stock API calls: listing events / can() / apply()', "{$c1} / " . ($c2 - $c1) . ' / ' . ($c3 - $c2));
    $stock->calls = 0;
    $o4 = new Order('C-2');
    $r4->applyTo($o4, 'pay', ['orderId' => 'C-2']);   // new -> confirmed, onEnter auto_pack
    show('stock API calls for one pay that chains into an onEnter auto_pack', (string) $stock->calls . "  (final state {$o4->getState()})");

    echo "\n### D5 commands with injected services\n";
    $o5 = new Order('R-1');
    $o5->setState('confirmed');
    runnerFor(definition(), $container)->applyTo($o5, 'refund', $manager);
    show('mailer (injected into NotifyCommand) recorded', json_encode($mailer->sent));

    echo "\n### D6 one event, two targets, chosen by a service-backed guard\n";
    foreach (['LOW-1' => 'low score', 'RISKY-1' => 'high score'] as $id => $label) {
        $o6 = new Order($id);
        $res = runnerFor(definition(), $container)->applyTo($o6, 'pay', ['orderId' => $id]);
        show("{$label} ({$id})", "pay -> {$res->getState()}");
    }
    echo "\n";
}
