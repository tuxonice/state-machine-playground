<?php

declare(strict_types=1);

namespace Poc\OrderStateMachine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poc\OrderStateMachine\EventDispatcher\OrderEventDispatcher;
use Poc\OrderStateMachine\EventListener\TransitionLogger;
use Poc\OrderStateMachine\EventListener\NotificationSimulator;
use Psr\EventDispatcher\EventDispatcherInterface;
use Tlab\StateMachine\Events\AfterTransition;
use Tlab\StateMachine\Events\BeforeTransition;
use Tlab\StateMachine\Events\TransitionBlocked;

class EventDispatcherTest extends TestCase
{
    public function testImplementsPsrInterface(): void
    {
        $dispatcher = new OrderEventDispatcher();
        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
    }

    public function testDispatchesAfterTransitionToLogger(): void
    {
        $logger = new TransitionLogger();
        $dispatcher = new OrderEventDispatcher($logger);

        $event = new AfterTransition('order', 'new', 'place_order', 'pending_payment', [], null);
        $dispatcher->dispatch($event);

        $entries = $logger->getEntries();
        $this->assertCount(1, $entries);
        $this->assertSame('after_transition', $entries[0]['type']);
        $this->assertSame('new', $entries[0]['source']);
        $this->assertSame('pending_payment', $entries[0]['target']);
        $this->assertSame('place_order', $entries[0]['event']);
    }

    public function testDispatchesBlockedToLogger(): void
    {
        $logger = new TransitionLogger();
        $dispatcher = new OrderEventDispatcher($logger);

        $event = new TransitionBlocked('order', 'confirmed', 'cancel', [], null);
        $dispatcher->dispatch($event);

        $entries = $logger->getEntries();
        $this->assertCount(1, $entries);
        $this->assertSame('transition_blocked', $entries[0]['type']);
        $this->assertSame('confirmed', $entries[0]['source']);
        $this->assertSame('cancel', $entries[0]['event']);
    }

    public function testNotificationSimulatorOnKeyTransitions(): void
    {
        $notifier = new NotificationSimulator();
        $dispatcher = new OrderEventDispatcher(notificationSimulator: $notifier);

        $dispatcher->dispatch(new AfterTransition('order', 'pending_payment', 'payment_success', 'confirmed', [], null));
        $dispatcher->dispatch(new AfterTransition('order', 'processing', 'ship', 'shipped', [], null));
        $dispatcher->dispatch(new AfterTransition('order', 'shipped', 'deliver', 'delivered', [], null));
        $dispatcher->dispatch(new AfterTransition('order', 'returned', 'refund', 'refunded', [], null));

        $notifications = $notifier->getNotifications();
        $this->assertCount(4, $notifications);
        $this->assertStringContainsString('confirmed', $notifications[0]['message']);
        $this->assertStringContainsString('shipped', $notifications[1]['message']);
        $this->assertStringContainsString('delivered', $notifications[2]['message']);
        $this->assertStringContainsStringIgnoringCase('refund', $notifications[3]['message']);
    }

    public function testNotificationSimulatorIgnoresNonKeyTransitions(): void
    {
        $notifier = new NotificationSimulator();
        $dispatcher = new OrderEventDispatcher(notificationSimulator: $notifier);

        $dispatcher->dispatch(new AfterTransition('order', 'new', 'place_order', 'pending_payment', [], null));
        $dispatcher->dispatch(new AfterTransition('order', 'confirmed', 'start_processing', 'processing', [], null));

        $this->assertEmpty($notifier->getNotifications());
    }

    public function testBeforeTransitionIsIgnored(): void
    {
        $logger = new TransitionLogger();
        $dispatcher = new OrderEventDispatcher($logger);

        $event = new BeforeTransition('order', 'new', 'place_order', 'pending_payment', [], null);
        $dispatcher->dispatch($event);

        $this->assertEmpty($logger->getEntries());
    }
}
