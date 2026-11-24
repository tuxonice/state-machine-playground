<?php

declare(strict_types=1);

namespace Experiment04\Guards\V2;

use Tlab\StateMachine\Conditions\ConditionInterface;

/** New behaviour for v2 lives in a new class, so released v1 keeps using the untouched HasPayment. */
final class HasPaymentAndAddress implements ConditionInterface
{
    public function check(array $data): bool
    {
        return !empty($data['payment_method']) && !empty($data['address']);
    }
}
