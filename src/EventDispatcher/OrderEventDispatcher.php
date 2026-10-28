<?php

declare(strict_types=1);

namespace Poc\OrderStateMachine\EventDispatcher;

use Poc\OrderStateMachine\EventListener\NotificationSimulator;
use Poc\OrderStateMachine\EventListener\TransitionLogger;
use Psr\EventDispatcher\EventDispatcherInterface;
use Tlab\StateMachine\Events\AfterTransition;
use Tlab\StateMachine\Events\TransitionBlocked;

class OrderEventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private ?TransitionLogger $transitionLogger = null,
        private ?NotificationSimulator $notificationSimulator = null,
    ) {
    }

    public function dispatch(object $event): object
    {
        if ($event instanceof AfterTransition) {
            $this->transitionLogger?->onAfterTransition($event);
            $this->notificationSimulator?->onAfterTransition($event);
        }

        if ($event instanceof TransitionBlocked) {
            $this->transitionLogger?->onTransitionBlocked($event);
        }

        return $event;
    }
}
