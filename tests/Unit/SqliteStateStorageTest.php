<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tlab\StatemachinePlayground\Entity\Order;
use Tlab\StatemachinePlayground\Storage\SqliteStateStorage;
use Tlab\StateMachine\Storage\StateStorageInterface;

class SqliteStateStorageTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'sm_test_') . '.sqlite';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testImplementsStateStorageInterface(): void
    {
        $storage = new SqliteStateStorage($this->dbPath);
        $this->assertInstanceOf(StateStorageInterface::class, $storage);
    }

    public function testReadReturnsNullForNewOrder(): void
    {
        $storage = new SqliteStateStorage($this->dbPath);
        $order = new Order('ORD-001');
        $this->assertNull($storage->read($order));
    }

    public function testWriteAndRead(): void
    {
        $storage = new SqliteStateStorage($this->dbPath);
        $order = new Order('ORD-001');

        $storage->write($order, 'confirmed');

        $this->assertSame('confirmed', $storage->read($order));
        $this->assertSame('confirmed', $order->getState());
    }

    public function testWriteUpdatesExistingState(): void
    {
        $storage = new SqliteStateStorage($this->dbPath);
        $order = new Order('ORD-001');

        $storage->write($order, 'confirmed');
        $storage->write($order, 'processing');

        $this->assertSame('processing', $storage->read($order));
    }

    public function testMultipleOrdersIndependent(): void
    {
        $storage = new SqliteStateStorage($this->dbPath);
        $order1 = new Order('ORD-001');
        $order2 = new Order('ORD-002');

        $storage->write($order1, 'confirmed');
        $storage->write($order2, 'shipped');

        $this->assertSame('confirmed', $storage->read($order1));
        $this->assertSame('shipped', $storage->read($order2));
    }

    public function testPersistsAcrossInstances(): void
    {
        $storage1 = new SqliteStateStorage($this->dbPath);
        $order = new Order('ORD-001');
        $storage1->write($order, 'processing');

        $storage2 = new SqliteStateStorage($this->dbPath);
        $freshOrder = new Order('ORD-001');
        $this->assertSame('processing', $storage2->read($freshOrder));
    }

    public function testListOrders(): void
    {
        $storage = new SqliteStateStorage($this->dbPath);
        $storage->write(new Order('ORD-001'), 'new');
        $storage->write(new Order('ORD-002'), 'confirmed');
        $storage->write(new Order('ORD-003'), 'shipped');

        $orders = $storage->listOrders();
        $this->assertCount(3, $orders);
        $this->assertSame('new', $orders['ORD-001']);
        $this->assertSame('confirmed', $orders['ORD-002']);
        $this->assertSame('shipped', $orders['ORD-003']);
    }

    public function testDeleteOrder(): void
    {
        $storage = new SqliteStateStorage($this->dbPath);
        $order = new Order('ORD-001');
        $storage->write($order, 'confirmed');

        $storage->deleteOrder('ORD-001');

        $freshOrder = new Order('ORD-001');
        $this->assertNull($storage->read($freshOrder));
        $this->assertEmpty($storage->listOrders());
    }
}
