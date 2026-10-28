<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tlab\StatemachinePlayground\Bootstrap;

class CancellationTest extends TestCase
{
    public function testCancelFromConfirmed(): void
    {
        $runner = Bootstrap::createRunner();
        $result = $runner->apply('confirmed', 'cancel', ['current_state' => 'confirmed']);
        $this->assertTrue($result->hasMoved());
        $this->assertSame('cancelled', $result->getState());
    }

    public function testCancelFromPaymentFailed(): void
    {
        $runner = Bootstrap::createRunner();
        $result = $runner->apply('payment_failed', 'cancel');
        $this->assertTrue($result->hasMoved());
        $this->assertSame('cancelled', $result->getState());
    }

    public function testRefundAfterCancellation(): void
    {
        $runner = Bootstrap::createRunner();
        $result = $runner->apply('cancelled', 'refund');
        $this->assertTrue($result->hasMoved());
        $this->assertSame('refunded', $result->getState());
    }
}
