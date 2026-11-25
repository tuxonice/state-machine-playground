<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StatemachinePlayground\Commands\LogTransitionCommand;
use Tlab\StateMachine\TransitionContext;

class CommandsTest extends TestCase
{
    public function testLogTransitionCommandRecordsData(): void
    {
        $command = new LogTransitionCommand();
        $command->run($this->context(['from' => 'new', 'to' => 'pending_payment', 'event' => 'place_order']));
        $log = $command->getLog();
        $this->assertCount(1, $log);
        $this->assertSame('place_order', $log[0]['event']);
    }

    /** @param array<mixed> $data */
    private function context(array $data): TransitionContext
    {
        return new TransitionContext('order', 'confirmed', 'cancel', 'cancelled', $data, null);
    }
}
