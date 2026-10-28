<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tlab\StatemachinePlayground\Bootstrap;
use Tlab\StatemachinePlayground\Entity\Order;
use Tlab\StatemachinePlayground\Storage\SqliteStateStorage;

class SqliteStorageTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'sm_int_') . '.sqlite';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testRunnerUsesStorageForTransitions(): void
    {
        $storage = new SqliteStateStorage($this->dbPath);
        $runner = Bootstrap::createRunner(storage: $storage);
        $order = new Order('ORD-INT-001');

        $runner->applyTo($order, 'place_order');
        $this->assertSame('pending_payment', $order->getState());

        $freshStorage = new SqliteStateStorage($this->dbPath);
        $freshOrder = new Order('ORD-INT-001');
        $this->assertSame('pending_payment', $freshStorage->read($freshOrder));
    }

    public function testFullHappyPathPersisted(): void
    {
        $storage = new SqliteStateStorage($this->dbPath);
        $runner = Bootstrap::createRunner(storage: $storage);
        $order = new Order('ORD-INT-002');

        $runner->applyTo($order, 'place_order');
        $runner->applyTo($order, 'payment_success', ['payment_method' => 'visa']);
        $runner->applyTo($order, 'start_processing');
        $runner->applyTo($order, 'ship');
        $runner->applyTo($order, 'deliver');

        $this->assertSame('delivered', $order->getState());

        $freshStorage = new SqliteStateStorage($this->dbPath);
        $freshOrder = new Order('ORD-INT-002');
        $this->assertSame('delivered', $freshStorage->read($freshOrder));
    }

    public function testMultipleOrdersPersisted(): void
    {
        $storage = new SqliteStateStorage($this->dbPath);
        $runner = Bootstrap::createRunner(storage: $storage);

        $order1 = new Order('ORD-INT-003');
        $order2 = new Order('ORD-INT-004');

        $runner->applyTo($order1, 'place_order');
        $runner->applyTo($order2, 'place_order');
        $runner->applyTo($order1, 'payment_success', ['payment_method' => 'visa']);

        $this->assertSame('confirmed', $order1->getState());
        $this->assertSame('pending_payment', $order2->getState());

        $orders = $storage->listOrders();
        $this->assertCount(2, $orders);
        $this->assertSame('confirmed', $orders['ORD-INT-003']);
        $this->assertSame('pending_payment', $orders['ORD-INT-004']);
    }

    public function testResumeOrderFromStorage(): void
    {
        $storage = new SqliteStateStorage($this->dbPath);
        $runner = Bootstrap::createRunner(storage: $storage);
        $order = new Order('ORD-INT-005');

        $runner->applyTo($order, 'place_order');
        $runner->applyTo($order, 'payment_success', ['payment_method' => 'visa']);

        $freshStorage = new SqliteStateStorage($this->dbPath);
        $freshRunner = Bootstrap::createRunner(storage: $freshStorage);
        $resumedOrder = new Order('ORD-INT-005');
        $freshStorage->read($resumedOrder);

        $result = $freshRunner->applyTo($resumedOrder, 'start_processing');
        $this->assertTrue($result->hasMoved());
        $this->assertSame('processing', $resumedOrder->getState());
    }
}
