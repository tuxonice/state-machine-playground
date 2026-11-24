<?php

declare(strict_types=1);

namespace Experiment08;

use Tlab\StateMachine\Commands\CommandInterface;

/** Stands in for a payment gateway call: an effect outside the database that cannot be rolled back. */
final class Charge implements CommandInterface
{
    public static int $runs = 0;

    public function run(array $data): void
    {
        self::$runs++;
    }
}
