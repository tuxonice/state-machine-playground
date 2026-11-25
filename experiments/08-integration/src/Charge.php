<?php

declare(strict_types=1);

namespace Experiment08;

use Tlab\StateMachine\Commands\CommandInterface;

use Tlab\StateMachine\TransitionContext;

/** Stands in for a payment gateway call: an effect outside the database that cannot be rolled back. */
final class Charge implements CommandInterface
{
    public static int $runs = 0;

    public function run(TransitionContext $context): void
    {
        self::$runs++;
    }
}
