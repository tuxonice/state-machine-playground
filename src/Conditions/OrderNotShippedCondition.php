<?php

declare(strict_types=1);

namespace Poc\OrderStateMachine\Conditions;

use Tlab\StateMachine\Conditions\ConditionInterface;

class OrderNotShippedCondition implements ConditionInterface
{
    private const POST_SHIP_STATES = [
        'shipped',
        'delivered',
        'lost',
        'return_requested',
        'returned',
        'refunded',
    ];

    public function check(array $data): bool
    {
        if (!isset($data['current_state'])) {
            return true;
        }

        return !in_array($data['current_state'], self::POST_SHIP_STATES, true);
    }
}
