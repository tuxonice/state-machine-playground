<?php

declare(strict_types=1);

namespace Poc\OrderStateMachine\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Poc\OrderStateMachine\Bootstrap;
use Poc\OrderStateMachine\Entity\Order;
use Poc\OrderStateMachine\EventListener\NotificationSimulator;
use Poc\OrderStateMachine\EventListener\TransitionLogger;
use Tlab\StateMachine\Scheduler\InMemoryTimeoutScheduler;

class TimeoutTest extends TestCase
{
    public function testTimeoutScheduledOnEnteringPendingPayment(): void
    {
        $scheduler = new InMemoryTimeoutScheduler();
        $runner = Bootstrap::createRunner(scheduler: $scheduler);

        $order = new Order('ORD-T01');
        $runner->applyTo($order, 'place_order');
        $this->assertSame('pending_payment', $order->getState());

        $scheduled = $scheduler->all();
        $this->assertCount(1, $scheduled);
        $this->assertSame('auto_cancel', $scheduled[0]->getEvent());
        $this->assertSame('pending_payment', $scheduled[0]->getState());
    }

    public function testTimeoutFiresAfter30Minutes(): void
    {
        $scheduler = new InMemoryTimeoutScheduler();
        $logger = new TransitionLogger();
        $runner = Bootstrap::createRunner(transitionLogger: $logger, scheduler: $scheduler);

        $order = new Order('ORD-T02');
        $runner->applyTo($order, 'place_order');
        $this->assertSame('pending_payment', $order->getState());

        $future = new DateTimeImmutable('+31 minutes');
        $results = $runner->processTimeouts($future);

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->hasMoved());
        $this->assertSame('cancelled', $order->getState());

        $entries = $logger->getEntries();
        $cancelEntry = array_filter($entries, fn($e) => $e['event'] === 'auto_cancel');
        $this->assertCount(1, $cancelEntry);
    }

    public function testTimeoutDoesNotFireBeforeDue(): void
    {
        $scheduler = new InMemoryTimeoutScheduler();
        $runner = Bootstrap::createRunner(scheduler: $scheduler);

        $order = new Order('ORD-T03');
        $runner->applyTo($order, 'place_order');

        $tooEarly = new DateTimeImmutable('+20 minutes');
        $results = $runner->processTimeouts($tooEarly);

        $this->assertEmpty($results);
        $this->assertSame('pending_payment', $order->getState());
    }

    public function testTimeoutCancelledWhenPaymentSucceeds(): void
    {
        $scheduler = new InMemoryTimeoutScheduler();
        $runner = Bootstrap::createRunner(scheduler: $scheduler);

        $order = new Order('ORD-T04');
        $runner->applyTo($order, 'place_order');
        $this->assertCount(1, $scheduler->all());

        $runner->applyTo($order, 'payment_success', ['payment_method' => 'credit_card']);
        $this->assertSame('confirmed', $order->getState());

        // Timeout should be cancelled (replaced by confirmed state's timeouts, which are none)
        $this->assertEmpty($scheduler->all());

        $future = new DateTimeImmutable('+31 minutes');
        $results = $runner->processTimeouts($future);
        $this->assertEmpty($results);
        $this->assertSame('confirmed', $order->getState());
    }

    public function testTimeoutSkippedWhenStateChanged(): void
    {
        $scheduler = new InMemoryTimeoutScheduler();
        $runner = Bootstrap::createRunner(scheduler: $scheduler);

        $order = new Order('ORD-T05');
        $runner->applyTo($order, 'place_order');

        // Manually fail payment before timeout fires
        $runner->applyTo($order, 'payment_failure');
        $this->assertSame('payment_failed', $order->getState());

        // Even if we process timeouts after 30min, order already left pending_payment
        $future = new DateTimeImmutable('+31 minutes');
        $results = $runner->processTimeouts($future);
        $this->assertEmpty($results);
        $this->assertSame('payment_failed', $order->getState());
    }

    public function testMultipleOrdersWithIndependentTimeouts(): void
    {
        $scheduler = new InMemoryTimeoutScheduler();
        $runner = Bootstrap::createRunner(scheduler: $scheduler);

        $order1 = new Order('ORD-T06');
        $order2 = new Order('ORD-T07');

        $runner->applyTo($order1, 'place_order');
        $runner->applyTo($order2, 'place_order');
        $this->assertCount(2, $scheduler->all());

        // Pay order1, leaving order2 pending
        $runner->applyTo($order1, 'payment_success', ['payment_method' => 'paypal']);
        $this->assertCount(1, $scheduler->all());

        $future = new DateTimeImmutable('+31 minutes');
        $results = $runner->processTimeouts($future);

        $this->assertCount(1, $results);
        $this->assertSame('confirmed', $order1->getState());
        $this->assertSame('cancelled', $order2->getState());
    }

    public function testNotificationFiredOnAutoCancel(): void
    {
        $scheduler = new InMemoryTimeoutScheduler();
        $notifier = new NotificationSimulator();
        $runner = Bootstrap::createRunner(notificationSimulator: $notifier, scheduler: $scheduler);

        $order = new Order('ORD-T08');
        $runner->applyTo($order, 'place_order');

        $future = new DateTimeImmutable('+31 minutes');
        $runner->processTimeouts($future);

        $this->assertSame('cancelled', $order->getState());
        // cancelled is not a notification target state, so no notification
        $this->assertEmpty($notifier->getNotifications());
    }
}
