<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StatemachinePlayground\Conditions\OrderNotShippedCondition;
use Tlab\StatemachinePlayground\Conditions\HasPaymentDataCondition;
use Tlab\StateMachine\TransitionContext;

class ConditionsTest extends TestCase
{
    public function testOrderNotShippedReturnsTrueForPreShipStates(): void
    {
        $condition = new OrderNotShippedCondition();
        $this->assertTrue($condition->check($this->context(['current_state' => 'confirmed'])));
        $this->assertTrue($condition->check($this->context(['current_state' => 'processing'])));
    }

    public function testOrderNotShippedReturnsFalseForPostShipStates(): void
    {
        $condition = new OrderNotShippedCondition();
        $this->assertFalse($condition->check($this->context(['current_state' => 'shipped'])));
        $this->assertFalse($condition->check($this->context(['current_state' => 'delivered'])));
    }

    public function testOrderNotShippedReturnsTrueWhenNoStateInData(): void
    {
        $condition = new OrderNotShippedCondition();
        $this->assertTrue($condition->check($this->context([])));
    }

    public function testHasPaymentDataReturnsTrueWithPaymentMethod(): void
    {
        $condition = new HasPaymentDataCondition();
        $this->assertTrue($condition->check($this->context(['payment_method' => 'credit_card'])));
    }

    public function testHasPaymentDataReturnsFalseWithoutPaymentMethod(): void
    {
        $condition = new HasPaymentDataCondition();
        $this->assertFalse($condition->check($this->context([])));
        $this->assertFalse($condition->check($this->context(['payment_method' => ''])));
    }

    /** @param array<mixed> $data */
    private function context(array $data): TransitionContext
    {
        return new TransitionContext('order', 'confirmed', 'cancel', 'cancelled', $data, null);
    }
}
