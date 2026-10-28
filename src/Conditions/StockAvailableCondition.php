<?php

declare(strict_types=1);

namespace Poc\OrderStateMachine\Conditions;

use Tlab\StateMachine\Conditions\ConditionInterface;

class StockAvailableCondition implements ConditionInterface
{
    public function check(array $data): bool
    {
        return !empty($data['stock_available']);
    }
}
