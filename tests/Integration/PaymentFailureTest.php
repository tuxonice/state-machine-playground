<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tlab\StatemachinePlayground\Bootstrap;

class PaymentFailureTest extends TestCase
{
    public function testPaymentFailureAndRetry(): void
    {
        $runner = Bootstrap::createRunner();
        $result = $runner->apply('pending_payment', 'payment_failure');
        $this->assertTrue($result->hasMoved());
        $this->assertSame('payment_failed', $result->getState());

        $result = $runner->apply('payment_failed', 'retry_payment');
        $this->assertTrue($result->hasMoved());
        $this->assertSame('pending_payment', $result->getState());
    }

    public function testPaymentSuccessWithoutPaymentDataIsBlocked(): void
    {
        $runner = Bootstrap::createRunner();
        $result = $runner->apply('pending_payment', 'payment_success', []);
        $this->assertFalse($result->hasMoved());
    }
}
