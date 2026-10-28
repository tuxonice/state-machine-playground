<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tlab\StatemachinePlayground\Bootstrap;

class ReturnFlowTest extends TestCase
{
    public function testReturnApprovedAndRefunded(): void
    {
        $runner = Bootstrap::createRunner();
        $result = $runner->apply('delivered', 'request_return');
        $this->assertTrue($result->hasMoved());
        $this->assertSame('return_requested', $result->getState());

        $result = $runner->apply('return_requested', 'approve_return');
        $this->assertTrue($result->hasMoved());
        $this->assertSame('returned', $result->getState());

        $result = $runner->apply('returned', 'refund');
        $this->assertTrue($result->hasMoved());
        $this->assertSame('refunded', $result->getState());
    }

    public function testReturnRejected(): void
    {
        $runner = Bootstrap::createRunner();
        $result = $runner->apply('return_requested', 'reject_return');
        $this->assertTrue($result->hasMoved());
        $this->assertSame('delivered', $result->getState());
    }
}
