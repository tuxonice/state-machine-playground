<?php

declare(strict_types=1);

namespace Experiment04\Guards;

use Tlab\StateMachine\Conditions\ConditionInterface;

/** Shared by v1 and v2 definitions of the old flow. Changing it changes every definition that references it. */
final class HasPayment implements ConditionInterface
{
    public function check(array $data): bool
    {
        return !empty($data['payment_method']);
    }
}
