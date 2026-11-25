<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StatemachinePlayground\Conditions\OrderNotShippedCondition;
use Tlab\StatemachinePlayground\Conditions\HasPaymentDataCondition;
use Tlab\StateMachine\TransitionContext;

class ConditionsTest extends TestCase
{
    public function testOrderNotShippedPassesForPreShipStates(): void
    {
        $condition = new OrderNotShippedCondition();
        $this->assertTrue($condition->check($this->from('confirmed')));
        $this->assertTrue($condition->check($this->from('processing')));
    }

    public function testOrderNotShippedFailsForPostShipStates(): void
    {
        $condition = new OrderNotShippedCondition();
        $this->assertFalse($condition->check($this->from('shipped')));
        $this->assertFalse($condition->check($this->from('delivered')));
    }

    public function testOrderNotShippedTrustsTheTransitionNotTheCallersData(): void
    {
        $condition = new OrderNotShippedCondition();
        $this->assertTrue($condition->check($this->from('confirmed', ['current_state' => 'shipped'])));
        $this->assertFalse($condition->check($this->from('shipped', ['current_state' => 'confirmed'])));
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
    private function from(string $source, array $data = []): TransitionContext
    {
        return new TransitionContext('order', $source, 'cancel', 'cancelled', $data, null);
    }

    /** @param array<mixed> $data */
    private function context(array $data): TransitionContext
    {
        return new TransitionContext('order', 'confirmed', 'cancel', 'cancelled', $data, null);
    }
}
