<?php

declare(strict_types=1);

namespace Poc\OrderStateMachine\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Poc\OrderStateMachine\Bootstrap;

class HappyPathTest extends TestCase
{
    public function testFullHappyPath(): void
    {
        $runner = Bootstrap::createRunner();
        $state = 'new';

        $result = $runner->apply($state, 'place_order');
        $this->assertTrue($result->hasMoved());
        $state = $result->getState();
        $this->assertSame('pending_payment', $state);

        $result = $runner->apply($state, 'payment_success', ['payment_method' => 'credit_card']);
        $this->assertTrue($result->hasMoved());
        $state = $result->getState();
        $this->assertSame('confirmed', $state);

        $steps = [
            ['start_processing', [], 'processing'],
            ['ship', [], 'shipped'],
            ['deliver', [], 'delivered'],
        ];
        foreach ($steps as [$event, $data, $expected]) {
            $result = $runner->apply($state, $event, $data);
            $this->assertTrue($result->hasMoved());
            $state = $result->getState();
            $this->assertSame($expected, $state);
        }
    }
}
