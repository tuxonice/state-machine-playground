<?php

declare(strict_types=1);

namespace Poc\OrderStateMachine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poc\OrderStateMachine\Entity\Order;
use Tlab\StateMachine\Storage\StatefulInterface;

class OrderTest extends TestCase
{
    public function testImplementsStatefulInterface(): void
    {
        $order = new Order('ORD-001');
        $this->assertInstanceOf(StatefulInterface::class, $order);
    }

    public function testInitialStateIsNull(): void
    {
        $order = new Order('ORD-001');
        $this->assertNull($order->getState());
    }

    public function testGetAndSetState(): void
    {
        $order = new Order('ORD-001');
        $order->setState('confirmed');
        $this->assertSame('confirmed', $order->getState());
    }

    public function testGetId(): void
    {
        $order = new Order('ORD-42');
        $this->assertSame('ORD-42', $order->getId());
    }

    public function testPaymentMethodDefaultsToNull(): void
    {
        $order = new Order('ORD-001');
        $this->assertNull($order->getPaymentMethod());
    }

    public function testGetAndSetPaymentMethod(): void
    {
        $order = new Order('ORD-001');
        $order->setPaymentMethod('credit_card');
        $this->assertSame('credit_card', $order->getPaymentMethod());
    }
}
