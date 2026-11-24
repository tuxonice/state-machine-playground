<?php

declare(strict_types=1);

use Tlab\StateMachine\Storage\StateStorageInterface;

/**
 * Decorator that sleeps after every read. It models the time a real app spends between reading the
 * state and writing it back: guard calls to other services, a remote database, slow listeners.
 * With a delay of 0 it changes nothing, so the "natural" race is measured as well.
 */
final class DelayingStorage implements StateStorageInterface
{
    public function __construct(private StateStorageInterface $inner, private int $delayUs)
    {
    }

    public function read(object $subject): ?string
    {
        $state = $this->inner->read($subject);
        if ($this->delayUs > 0) {
            usleep($this->delayUs);
        }

        return $state;
    }

    public function write(object $subject, string $state): void
    {
        $this->inner->write($subject, $state);
    }
}
