<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tlab\StatemachinePlayground\Bootstrap;
use Tlab\StateMachine\Exceptions\UnknownEventException;
use Tlab\StateMachine\TransitionStatus;

class EdgeCasesTest extends TestCase
{
    public function testInvalidEventThrowsException(): void
    {
        $runner = Bootstrap::createRunner();
        $this->expectException(UnknownEventException::class);
        $runner->apply('new', 'nonexistent_event');
    }

    public function testCannotShipFromNew(): void
    {
        $runner = Bootstrap::createRunner();
        $result = $runner->apply('new', 'ship');
        $this->assertSame(TransitionStatus::NoTransition, $result->getStatus());
    }

    public function testAvailableEventsFromNew(): void
    {
        $runner = Bootstrap::createRunner();
        $events = $runner->availableEvents('new');
        $this->assertSame(['place_order'], $events);
    }

    public function testAvailableEventsFromShipped(): void
    {
        $runner = Bootstrap::createRunner();
        $events = $runner->availableEvents('shipped');
        $this->assertContains('deliver', $events);
        $this->assertContains('lost_in_transit', $events);
    }

    public function testLostInTransit(): void
    {
        $runner = Bootstrap::createRunner();
        $result = $runner->apply('shipped', 'lost_in_transit');
        $this->assertTrue($result->hasMoved());
        $this->assertSame('lost', $result->getState());
    }

    public function testRefundedIsTerminal(): void
    {
        $runner = Bootstrap::createRunner();
        $events = $runner->availableEvents('refunded');
        $this->assertEmpty($events);
    }

    public function testLostIsTerminal(): void
    {
        $runner = Bootstrap::createRunner();
        $events = $runner->availableEvents('lost');
        $this->assertEmpty($events);
    }

    public function testInitialStateFromConfig(): void
    {
        $runner = Bootstrap::createRunner();
        $initialState = $runner->getStateMachine()->getCurrentState();
        $this->assertSame('new', $initialState);
    }
}
