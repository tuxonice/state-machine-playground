<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\Conditions;

use Tlab\StateMachine\Conditions\ConditionInterface;
use Tlab\StateMachine\TransitionContext;

class StockAvailableCondition implements ConditionInterface
{
    public function check(TransitionContext $context): bool
    {
        return !empty($context->data['stock_available']);
    }
}
