<?php

declare(strict_types=1);

namespace Poc\OrderStateMachine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poc\OrderStateMachine\Conditions\StockAvailableCondition;
use Tlab\StateMachine\Conditions\ConditionInterface;

class StockAvailableConditionTest extends TestCase
{
    public function testImplementsConditionInterface(): void
    {
        $condition = new StockAvailableCondition();
        $this->assertInstanceOf(ConditionInterface::class, $condition);
    }

    public function testReturnsTrueWhenStockAvailable(): void
    {
        $condition = new StockAvailableCondition();
        $this->assertTrue($condition->check(['stock_available' => true]));
    }

    public function testReturnsFalseWhenStockNotAvailable(): void
    {
        $condition = new StockAvailableCondition();
        $this->assertFalse($condition->check(['stock_available' => false]));
    }

    public function testReturnsFalseWhenStockKeyMissing(): void
    {
        $condition = new StockAvailableCondition();
        $this->assertFalse($condition->check([]));
    }
}
