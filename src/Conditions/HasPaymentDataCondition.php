<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\Conditions;

use Tlab\StateMachine\Conditions\ConditionInterface;
use Tlab\StateMachine\TransitionContext;

class HasPaymentDataCondition implements ConditionInterface
{
    public function check(TransitionContext $context): bool
    {
        return isset($context->data['payment_method']) && $context->data['payment_method'] !== '';
    }
}
