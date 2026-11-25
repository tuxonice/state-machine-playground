<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\Conditions;

use Tlab\StateMachine\Conditions\ConditionInterface;
use Tlab\StateMachine\TransitionContext;

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

    public function check(TransitionContext $context): bool
    {
        if (!isset($context->data['current_state'])) {
            return true;
        }

        return !in_array($context->data['current_state'], self::POST_SHIP_STATES, true);
    }
}
