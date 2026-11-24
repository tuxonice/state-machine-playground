<?php

declare(strict_types=1);

namespace Experiment08;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use Tlab\StateMachine\Commands\CommandInterface;
use Tlab\StateMachine\Conditions\ConditionInterface;
use Tlab\StateMachine\Exceptions\StateMachineException;
use Tlab\StateMachine\Models\StateMachine;
use Tlab\StateMachine\Reader\DefinitionReader;

/**
 * Static checks for a state machine definition, one rule per finding from experiments 2 to 6.
 *
 *  R1 schema/validation   R2 guard/command resolvable   R3 one class name per condition
 *  R4 self-loop on a state with timeouts (timers restart)   R5 onEnter cycle (side effects repeat)
 *  R6 shadowed transition (after an unconditional one)   R7 guard on a timeout event (dropped, not retried)
 *  R8 unreachable state   R9 event not used / not defined
 */
final class DefinitionChecker
{
    private const CLASS_NAME = '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/';

    public function __construct(private ?ContainerInterface $container = null)
    {
    }

    /** @return list<array{rule: string, level: string, message: string}> */
    public function checkFile(string $path): array
    {
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            return [$this->f('R1', 'error', 'not valid JSON')];
        }
        try {
            $machine = (new DefinitionReader())->readArray($raw);
        } catch (StateMachineException $e) {
            return [$this->f('R1', 'error', substr($e->getMessage(), 0, 160))];
        }

        return array_merge(
            $this->classes($machine),
            $this->timerRules($machine),
            $this->onEnterCycles($machine),
            $this->shadowing($machine),
            $this->reachability($machine),
            $this->eventUse($machine),
        );
    }

    /** @return list<array{rule: string, level: string, message: string}> */
    private function classes(StateMachine $m): array
    {
        $out = [];
        $refs = [];
        foreach ($m->getTransitions() as $t) {
            $refs[] = [$t->getCondition(), ConditionInterface::class, "{$t->getSource()} --{$t->getEvent()}--> {$t->getTarget()}"];
            $refs[] = [$t->getCommand(), CommandInterface::class, "{$t->getSource()} --{$t->getEvent()}--> {$t->getTarget()}"];
        }
        foreach ($m->getEvents() as $e) {
            $refs[] = [$e->getCommand(), CommandInterface::class, "event {$e->getName()}"];
        }
        foreach ($refs as [$class, $interface, $where]) {
            if ($class === null || $class === '') {
                continue;
            }
            if (!preg_match(self::CLASS_NAME, $class)) {
                $out[] = $this->f('R3', 'error', "'{$class}' at {$where} is not a single class name (compound conditions are not supported)");
                continue;
            }
            if (!class_exists($class)) {
                $out[] = $this->f('R2', 'error', "{$class} at {$where} does not exist");
                continue;
            }
            if (!is_subclass_of($class, $interface)) {
                $out[] = $this->f('R2', 'error', "{$class} at {$where} must implement {$interface}");
                continue;
            }
            $ctor = (new ReflectionClass($class))->getConstructor();
            if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
                if ($this->container === null) {
                    $out[] = $this->f('R2', 'warning', "{$class} at {$where} needs constructor arguments; pass --container to verify it is registered");
                } elseif (!$this->container->has($class)) {
                    $out[] = $this->f('R2', 'error', "{$class} at {$where} needs constructor arguments but is not registered in the container");
                }
            }
        }

        return $out;
    }

    /** @return list<array{rule: string, level: string, message: string}> */
    private function timerRules(StateMachine $m): array
    {
        $out = [];
        foreach ($m->getStates() as $s) {
            $name = $s->getName();
            if ($m->getTimeoutEvents($name) === []) {
                continue;
            }
            foreach ($m->getTransitions() as $t) {
                if ($t->getSource() === $name && $t->getTarget() === $name) {
                    $out[] = $this->f('R4', 'error', "state '{$name}' has timeouts and a self-loop ('{$t->getEvent()}'): every move restarts all its timers, so the other timeouts may never fire");
                }
            }
            foreach ($m->getTimeoutEvents($name) as $e) {
                foreach ($m->getTransitionsFor($name, $e->getName()) as $t) {
                    if ($t->getCondition() !== null) {
                        $out[] = $this->f('R7', 'warning', "timeout event '{$e->getName()}' from '{$name}' has a guard: if it fails when due, the timeout is dropped and never retried");
                    }
                }
            }
        }

        return $out;
    }

    /** @return list<array{rule: string, level: string, message: string}> */
    private function onEnterCycles(StateMachine $m): array
    {
        $onEnter = [];
        foreach ($m->getEvents() as $e) {
            if ($e->isOnEnter()) {
                $onEnter[$e->getName()] = true;
            }
        }
        $edges = [];
        foreach ($m->getTransitions() as $t) {
            if (isset($onEnter[$t->getEvent()])) {
                $edges[$t->getSource()][] = [$t->getTarget(), $t->getCondition() === null];
            }
        }
        $out = [];
        $seen = [];
        $walk = function (string $node, array $path, bool $allUnconditional) use (&$walk, $edges, &$out, &$seen): void {
            foreach ($edges[$node] ?? [] as [$next, $unconditional]) {
                $unc = $allUnconditional && $unconditional;
                if (in_array($next, $path, true)) {
                    $cycle = array_slice($path, array_search($next, $path, true));
                    $key = implode('>', $cycle);
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $out[] = $this->f('R5', $unc ? 'error' : 'warning', 'onEnter cycle ' . implode(' -> ', [...$cycle, $next]) . ($unc ? ' (unconditional: runs 50 times, with side effects, then throws)' : ' (guarded: only safe if a guard eventually fails)'));
                    }
                    continue;
                }
                $walk($next, [...$path, $next], $unc);
            }
        };
        foreach (array_keys($edges) as $start) {
            $walk($start, [$start], true);
        }

        return $out;
    }

    /** @return list<array{rule: string, level: string, message: string}> */
    private function shadowing(StateMachine $m): array
    {
        $out = [];
        $groups = [];
        foreach ($m->getTransitions() as $t) {
            $groups["{$t->getSource()}|{$t->getEvent()}"][] = $t;
        }
        foreach ($groups as $key => $ts) {
            foreach ($ts as $i => $t) {
                if ($t->getCondition() === null && isset($ts[$i + 1])) {
                    [$src, $event] = explode('|', $key);
                    $out[] = $this->f('R6', 'error', "'{$event}' from '{$src}': an unconditional transition to '{$t->getTarget()}' comes before {$this->count(count($ts) - $i - 1)} more, which can never be reached");
                    break;
                }
            }
        }

        return $out;
    }

    /** @return list<array{rule: string, level: string, message: string}> */
    private function reachability(StateMachine $m): array
    {
        $start = $m->getCurrentState();
        if ($start === null) {
            return [];
        }
        $reached = [$start => true];
        $queue = [$start];
        while ($queue) {
            $s = array_shift($queue);
            foreach ($m->getTransitions() as $t) {
                if ($t->getSource() === $s && !isset($reached[$t->getTarget()])) {
                    $reached[$t->getTarget()] = true;
                    $queue[] = $t->getTarget();
                }
            }
        }
        $out = [];
        foreach ($m->getStates() as $s) {
            if (!isset($reached[$s->getName()])) {
                $out[] = $this->f('R8', 'warning', "state '{$s->getName()}' is unreachable from the initial state '{$start}'");
            }
        }

        return $out;
    }

    /** @return list<array{rule: string, level: string, message: string}> */
    private function eventUse(StateMachine $m): array
    {
        $out = [];
        $used = [];
        $defined = [];
        foreach ($m->getEvents() as $e) {
            $defined[$e->getName()] = true;
        }
        foreach ($m->getTransitions() as $t) {
            $used[$t->getEvent()] = true;
            if (!isset($defined[$t->getEvent()])) {
                $out[] = $this->f('R9', 'error', "transition {$t->getSource()} -> {$t->getTarget()} uses undefined event '{$t->getEvent()}'");
            }
        }
        foreach (array_keys($defined) as $name) {
            if (!isset($used[$name])) {
                $out[] = $this->f('R9', 'warning', "event '{$name}' is defined but no transition uses it");
            }
        }

        return $out;
    }

    private function count(int $n): string
    {
        return $n === 1 ? '1 transition' : "{$n} transitions";
    }

    /** @return array{rule: string, level: string, message: string} */
    private function f(string $rule, string $level, string $message): array
    {
        return ['rule' => $rule, 'level' => $level, 'message' => $message];
    }
}
