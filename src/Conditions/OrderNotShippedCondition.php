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

    /**
     * Decides by the state the transition leaves, which the runner fills in, not by anything the caller passes.
     */
    public function check(TransitionContext $context): bool
    {
        return !in_array($context->source, self::POST_SHIP_STATES, true);
    }
}
