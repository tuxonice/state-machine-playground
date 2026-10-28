<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\Commands;

use Tlab\StateMachine\Commands\CommandInterface;

class LogTransitionCommand implements CommandInterface
{
    /** @var array<int, array<mixed>> */
    private array $log = [];

    public function run(array $data): void
    {
        $this->log[] = $data;
    }

    /** @return array<int, array<mixed>> */
    public function getLog(): array
    {
        return $this->log;
    }
}
