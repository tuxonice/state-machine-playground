<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\EventListener;

use Tlab\StateMachine\Events\AfterTransition;
use Tlab\StateMachine\Events\TransitionBlocked;

class TransitionLogger
{
    /** @var array<int, array{type: string, source: string, event: string, target?: string, timestamp: string}> */
    private array $entries = [];

    public function onAfterTransition(AfterTransition $event): void
    {
        $this->entries[] = [
            'type' => 'after_transition',
            'source' => $event->source,
            'event' => $event->event,
            'target' => $event->target,
            'timestamp' => date('H:i:s'),
        ];
    }

    public function onTransitionBlocked(TransitionBlocked $event): void
    {
        $this->entries[] = [
            'type' => 'transition_blocked',
            'source' => $event->source,
            'event' => $event->event,
            'timestamp' => date('H:i:s'),
        ];
    }

    /** @return array<int, array{type: string, source: string, event: string, target?: string, timestamp: string}> */
    public function getEntries(): array
    {
        return $this->entries;
    }
}
