<?php

declare(strict_types=1);

namespace Experiment04\Guards\V2;

use Tlab\StateMachine\Conditions\ConditionInterface;

use Tlab\StateMachine\TransitionContext;

/** New behaviour for v2 lives in a new class, so released v1 keeps using the untouched HasPayment. */
final class HasPaymentAndAddress implements ConditionInterface
{
    public function check(TransitionContext $context): bool
    {
        return !empty($context->data['payment_method']) && !empty($context->data['address']);
    }
}
