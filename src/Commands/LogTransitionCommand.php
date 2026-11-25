<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\Commands;

use Tlab\StateMachine\Commands\CommandInterface;
use Tlab\StateMachine\TransitionContext;

class LogTransitionCommand implements CommandInterface
{
    /** @var array<int, array<mixed>> */
    private array $log = [];

    public function run(TransitionContext $context): void
    {
        $this->log[] = $context->data;
    }

    /** @return array<int, array<mixed>> */
    public function getLog(): array
    {
        return $this->log;
    }
}
