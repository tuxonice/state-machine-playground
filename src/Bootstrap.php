<?php

declare(strict_types=1);

namespace Poc\OrderStateMachine;

use Poc\OrderStateMachine\EventDispatcher\OrderEventDispatcher;
use Poc\OrderStateMachine\EventListener\NotificationSimulator;
use Poc\OrderStateMachine\EventListener\TransitionLogger;
use Tlab\StateMachine\Reader\DefinitionReader;
use Tlab\StateMachine\Scheduler\TimeoutScheduler;
use Tlab\StateMachine\StateMachineRunner;
use Tlab\StateMachine\Storage\StateStorageInterface;

class Bootstrap
{
    public static function createRunner(
        ?string $configPath = null,
        ?TransitionLogger $transitionLogger = null,
        ?NotificationSimulator $notificationSimulator = null,
        ?TimeoutScheduler $scheduler = null,
        ?StateStorageInterface $storage = null,
    ): StateMachineRunner {
        $configPath ??= self::getConfigPath();
        $reader = new DefinitionReader();
        $stateMachine = $reader->readFile($configPath);
        $dispatcher = new OrderEventDispatcher($transitionLogger, $notificationSimulator);

        return new StateMachineRunner($stateMachine, dispatcher: $dispatcher, scheduler: $scheduler, storage: $storage);
    }

    public static function getConfigPath(): string
    {
        return __DIR__ . '/../config/order-state-machine.json';
    }
}
