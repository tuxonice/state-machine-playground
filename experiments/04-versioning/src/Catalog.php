<?php

declare(strict_types=1);

namespace Experiment04;

use Tlab\StateMachine\Models\StateMachine;
use Tlab\StateMachine\Reader\DefinitionReader;
use Tlab\StateMachine\Registry\StateMachineRegistry;
use Tlab\StateMachine\Scheduler\TimeoutScheduler;
use Tlab\StateMachine\StateMachineRunner;
use Tlab\StateMachine\Storage\StateStorageInterface;

/**
 * Loads every definitions/<name>-v<N>.json and registers each under "<name>@v<N>".
 * The "name" field inside the JSON is the same for all versions; the registry key carries the version.
 */
final class Catalog
{
    public readonly StateMachineRegistry $registry;
    /** @var array<int, StateMachine> */
    private array $machines = [];
    /** @var array<int, string> */
    private array $files = [];

    public function __construct(
        private string $dir,
        private string $name,
        StateStorageInterface $storage,
        TimeoutScheduler $scheduler,
        ?int $onlyUpToVersion = null,
    ) {
        $this->registry = new StateMachineRegistry();
        $reader = new DefinitionReader();
        $files = glob("{$dir}/{$name}-v*.json") ?: [];
        natsort($files);
        foreach ($files as $file) {
            preg_match('/-v(\d+)\.json$/', $file, $m);
            $version = (int) $m[1];
            if ($onlyUpToVersion !== null && $version > $onlyUpToVersion) {
                continue;
            }
            $machine = $reader->readFile($file);
            $this->machines[$version] = $machine;
            $this->files[$version] = $file;
            $this->registry->register(new StateMachineRunner($machine, storage: $storage, scheduler: $scheduler), self::key($name, $version));
        }
    }

    public static function key(string $name, int $version): string
    {
        return "{$name}@v{$version}";
    }

    public function latest(): int
    {
        return max(array_keys($this->machines));
    }

    /** @return int[] */
    public function versions(): array
    {
        return array_keys($this->machines);
    }

    public function machine(int $version): StateMachine
    {
        return $this->machines[$version];
    }

    public function file(int $version): string
    {
        return $this->files[$version];
    }

    /** @return string[] states with no outgoing transition in that version */
    public function terminalStates(int $version): array
    {
        $machine = $this->machines[$version];
        $sources = array_map(fn($t) => $t->getSource(), $machine->getTransitions());

        return array_values(array_filter(
            array_map(fn($s) => $s->getName(), $machine->getStates()),
            fn($s) => !in_array($s, $sources, true)
        ));
    }

    /** @return string[] FQCNs of every guard and command the version references */
    public function referencedClasses(int $version): array
    {
        $machine = $this->machines[$version];
        $classes = [];
        foreach ($machine->getTransitions() as $t) {
            $classes[] = $t->getCondition();
            $classes[] = $t->getCommand();
        }
        foreach ($machine->getEvents() as $e) {
            $classes[] = $e->getCommand();
        }
        $classes = array_unique(array_filter($classes));
        sort($classes);

        return $classes;
    }
}
