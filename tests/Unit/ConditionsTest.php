<?php

declare(strict_types=1);

namespace Poc\OrderStateMachine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poc\OrderStateMachine\Conditions\OrderNotShippedCondition;
use Poc\OrderStateMachine\Conditions\HasPaymentDataCondition;

class ConditionsTest extends TestCase
{
    public function testOrderNotShippedReturnsTrueForPreShipStates(): void
    {
        $condition = new OrderNotShippedCondition();
        $this->assertTrue($condition->check(['current_state' => 'confirmed']));
        $this->assertTrue($condition->check(['current_state' => 'processing']));
    }

    public function testOrderNotShippedReturnsFalseForPostShipStates(): void
    {
        $condition = new OrderNotShippedCondition();
        $this->assertFalse($condition->check(['current_state' => 'shipped']));
        $this->assertFalse($condition->check(['current_state' => 'delivered']));
    }

    public function testOrderNotShippedReturnsTrueWhenNoStateInData(): void
    {
        $condition = new OrderNotShippedCondition();
        $this->assertTrue($condition->check([]));
    }

    public function testHasPaymentDataReturnsTrueWithPaymentMethod(): void
    {
        $condition = new HasPaymentDataCondition();
        $this->assertTrue($condition->check(['payment_method' => 'credit_card']));
    }

    public function testHasPaymentDataReturnsFalseWithoutPaymentMethod(): void
    {
        $condition = new HasPaymentDataCondition();
        $this->assertFalse($condition->check([]));
        $this->assertFalse($condition->check(['payment_method' => '']));
    }
}
