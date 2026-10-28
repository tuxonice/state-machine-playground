<?php

declare(strict_types=1);

namespace Poc\OrderStateMachine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poc\OrderStateMachine\Commands\LogTransitionCommand;

class CommandsTest extends TestCase
{
    public function testLogTransitionCommandRecordsData(): void
    {
        $command = new LogTransitionCommand();
        $command->run(['from' => 'new', 'to' => 'pending_payment', 'event' => 'place_order']);
        $log = $command->getLog();
        $this->assertCount(1, $log);
        $this->assertSame('place_order', $log[0]['event']);
    }
}
