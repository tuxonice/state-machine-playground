<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tlab\StatemachinePlayground\Bootstrap;
use Tlab\StatemachinePlayground\Entity\Order;
use Tlab\StatemachinePlayground\EventListener\TransitionLogger;

class OnEnterTest extends TestCase
{
    public function testAutoProcessFiresWhenStockAvailable(): void
    {
        $logger = new TransitionLogger();
        $runner = Bootstrap::createRunner(transitionLogger: $logger);

        $order = new Order('ORD-AE01');
        $runner->applyTo($order, 'place_order');
        $result = $runner->applyTo($order, 'payment_success', [
            'payment_method' => 'credit_card',
            'stock_available' => true,
        ]);

        $this->assertTrue($result->hasMoved());
        $this->assertSame('processing', $order->getState());

        // TransitionResult should contain both events that fired
        $events = $result->getEvents();
        $this->assertContains('payment_success', $events);
        $this->assertContains('auto_process', $events);
    }

    public function testAutoProcessSkippedWhenStockNotAvailable(): void
    {
        $runner = Bootstrap::createRunner();

        $order = new Order('ORD-AE02');
        $runner->applyTo($order, 'place_order');
        $result = $runner->applyTo($order, 'payment_success', [
            'payment_method' => 'credit_card',
            'stock_available' => false,
        ]);

        $this->assertTrue($result->hasMoved());
        $this->assertSame('confirmed', $order->getState());
    }

    public function testAutoProcessSkippedWhenStockKeyMissing(): void
    {
        $runner = Bootstrap::createRunner();

        $order = new Order('ORD-AE03');
        $runner->applyTo($order, 'place_order');
        $result = $runner->applyTo($order, 'payment_success', [
            'payment_method' => 'credit_card',
        ]);

        $this->assertTrue($result->hasMoved());
        $this->assertSame('confirmed', $order->getState());
    }

    public function testManualStartProcessingStillWorks(): void
    {
        $runner = Bootstrap::createRunner();

        $order = new Order('ORD-AE04');
        $runner->applyTo($order, 'place_order');
        $runner->applyTo($order, 'payment_success', ['payment_method' => 'visa']);
        $this->assertSame('confirmed', $order->getState());

        $result = $runner->applyTo($order, 'start_processing');
        $this->assertTrue($result->hasMoved());
        $this->assertSame('processing', $order->getState());
    }

    public function testAutoProcessDispatchesEventsForBothTransitions(): void
    {
        $logger = new TransitionLogger();
        $runner = Bootstrap::createRunner(transitionLogger: $logger);

        $order = new Order('ORD-AE05');
        $runner->applyTo($order, 'place_order');
        $runner->applyTo($order, 'payment_success', [
            'payment_method' => 'credit_card',
            'stock_available' => true,
        ]);

        $entries = $logger->getEntries();
        $afterTransitions = array_filter($entries, fn($e) => $e['type'] === 'after_transition');
        $eventNames = array_column(array_values($afterTransitions), 'event');

        $this->assertContains('payment_success', $eventNames);
        $this->assertContains('auto_process', $eventNames);
    }

    public function testAutoProcessWithNotifications(): void
    {
        $notifier = new \Tlab\StatemachinePlayground\EventListener\NotificationSimulator();
        $runner = Bootstrap::createRunner(notificationSimulator: $notifier);

        $order = new Order('ORD-AE06');
        $runner->applyTo($order, 'place_order');
        $runner->applyTo($order, 'payment_success', [
            'payment_method' => 'credit_card',
            'stock_available' => true,
        ]);

        // Should get "confirmed" notification even though we auto-transitioned through it
        $notifications = $notifier->getNotifications();
        $targets = array_column($notifications, 'target');
        $this->assertContains('confirmed', $targets);
    }
}
