<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\Conditions;

use Tlab\StateMachine\Conditions\ConditionInterface;

class HasPaymentDataCondition implements ConditionInterface
{
    public function check(array $data): bool
    {
        return isset($data['payment_method']) && $data['payment_method'] !== '';
    }
}
