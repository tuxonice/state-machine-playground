<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tlab\StatemachinePlayground\Bootstrap;
use Tlab\StatemachinePlayground\Entity\Order;
use Tlab\StatemachinePlayground\EventListener\NotificationSimulator;
use Tlab\StatemachinePlayground\EventListener\TransitionLogger;

class EntityAndEventsTest extends TestCase
{
    public function testApplyToMovesOrderEntity(): void
    {
        $logger = new TransitionLogger();
        $runner = Bootstrap::createRunner(transitionLogger: $logger);

        $order = new Order('ORD-001');

        $result = $runner->applyTo($order, 'place_order');
        $this->assertTrue($result->hasMoved());
        $this->assertSame('pending_payment', $order->getState());
    }

    public function testEntityHappyPathWithEventLog(): void
    {
        $logger = new TransitionLogger();
        $notifier = new NotificationSimulator();
        $runner = Bootstrap::createRunner(transitionLogger: $logger, notificationSimulator: $notifier);

        $order = new Order('ORD-002');

        $runner->applyTo($order, 'place_order');
        $this->assertSame('pending_payment', $order->getState());

        $runner->applyTo($order, 'payment_success', ['payment_method' => 'credit_card']);
        $this->assertSame('confirmed', $order->getState());

        $runner->applyTo($order, 'start_processing');
        $this->assertSame('processing', $order->getState());

        $runner->applyTo($order, 'ship');
        $this->assertSame('shipped', $order->getState());

        $runner->applyTo($order, 'deliver');
        $this->assertSame('delivered', $order->getState());

        $entries = $logger->getEntries();
        $this->assertCount(5, $entries);
        $this->assertSame('place_order', $entries[0]['event']);
        $this->assertSame('deliver', $entries[4]['event']);

        $notifications = $notifier->getNotifications();
        $this->assertCount(3, $notifications);
        $this->assertSame('confirmed', $notifications[0]['target']);
        $this->assertSame('shipped', $notifications[1]['target']);
        $this->assertSame('delivered', $notifications[2]['target']);
    }

    public function testBlockedTransitionLogsEvent(): void
    {
        $logger = new TransitionLogger();
        $runner = Bootstrap::createRunner(transitionLogger: $logger);

        $order = new Order('ORD-003');
        $runner->applyTo($order, 'place_order');
        $runner->applyTo($order, 'payment_success', ['payment_method' => 'paypal']);
        $this->assertSame('confirmed', $order->getState());

        $result = $runner->applyTo($order, 'cancel', ['current_state' => 'shipped']);
        $this->assertFalse($result->hasMoved());
        $this->assertSame('confirmed', $order->getState());

        $blocked = array_filter($logger->getEntries(), fn($e) => $e['type'] === 'transition_blocked');
        $this->assertCount(1, $blocked);
    }

    public function testAvailableEventsForEntity(): void
    {
        $runner = Bootstrap::createRunner();
        $order = new Order('ORD-004');
        $runner->applyTo($order, 'place_order');

        $events = $runner->availableEventsFor($order);
        $this->assertContains('payment_success', $events);
        $this->assertContains('payment_failure', $events);
    }

    public function testRefundNotification(): void
    {
        $notifier = new NotificationSimulator();
        $runner = Bootstrap::createRunner(notificationSimulator: $notifier);

        $order = new Order('ORD-005');
        $runner->applyTo($order, 'place_order');
        $runner->applyTo($order, 'payment_success', ['payment_method' => 'credit_card']);
        $runner->applyTo($order, 'start_processing');
        $runner->applyTo($order, 'ship');
        $runner->applyTo($order, 'deliver');
        $runner->applyTo($order, 'request_return');
        $runner->applyTo($order, 'approve_return');
        $runner->applyTo($order, 'refund');
        $this->assertSame('refunded', $order->getState());

        $notifications = $notifier->getNotifications();
        $refundNotif = array_filter($notifications, fn($n) => $n['target'] === 'refunded');
        $this->assertCount(1, $refundNotif);
    }

    public function testCanApplyToEntity(): void
    {
        $runner = Bootstrap::createRunner();
        $order = new Order('ORD-006');
        $runner->applyTo($order, 'place_order');

        $this->assertTrue($runner->canApplyTo($order, 'payment_success', ['payment_method' => 'visa']));
        $this->assertFalse($runner->canApplyTo($order, 'payment_success', []));
    }

    public function testStringBasedApplyStillWorks(): void
    {
        $logger = new TransitionLogger();
        $runner = Bootstrap::createRunner(transitionLogger: $logger);

        $result = $runner->apply('new', 'place_order');
        $this->assertTrue($result->hasMoved());
        $this->assertSame('pending_payment', $result->getState());

        $this->assertCount(1, $logger->getEntries());
    }
}
