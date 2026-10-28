<?php

declare(strict_types=1);

namespace Poc\OrderStateMachine\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Poc\OrderStateMachine\Entity\Order;
use Poc\OrderStateMachine\Storage\SqliteTimeoutScheduler;
use Tlab\StateMachine\Scheduler\TimeoutScheduler;

class SqliteTimeoutSchedulerTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'sm_sched_') . '.sqlite';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testImplementsTimeoutScheduler(): void
    {
        $scheduler = new SqliteTimeoutScheduler($this->dbPath);
        $this->assertInstanceOf(TimeoutScheduler::class, $scheduler);
    }

    public function testScheduleAndPullDue(): void
    {
        $scheduler = new SqliteTimeoutScheduler($this->dbPath);
        $order = new Order('ORD-001');
        $due = new DateTimeImmutable('2026-10-28 10:00:00');

        $scheduler->schedule($order, 'pending_payment', 'auto_cancel', $due);

        $pulled = $scheduler->pullDue(new DateTimeImmutable('2026-10-28 10:01:00'));
        $this->assertCount(1, $pulled);
        $this->assertSame('ORD-001', $pulled[0]->getSubject()->getId());
        $this->assertSame('pending_payment', $pulled[0]->getState());
        $this->assertSame('auto_cancel', $pulled[0]->getEvent());
    }

    public function testPullDueRemovesTimeout(): void
    {
        $scheduler = new SqliteTimeoutScheduler($this->dbPath);
        $order = new Order('ORD-001');
        $due = new DateTimeImmutable('2026-10-28 10:00:00');

        $scheduler->schedule($order, 'pending_payment', 'auto_cancel', $due);
        $scheduler->pullDue(new DateTimeImmutable('2026-10-28 10:01:00'));

        $second = $scheduler->pullDue(new DateTimeImmutable('2026-10-28 10:02:00'));
        $this->assertEmpty($second);
    }

    public function testNotDueYet(): void
    {
        $scheduler = new SqliteTimeoutScheduler($this->dbPath);
        $order = new Order('ORD-001');
        $due = new DateTimeImmutable('2026-10-28 10:30:00');

        $scheduler->schedule($order, 'pending_payment', 'auto_cancel', $due);

        $pulled = $scheduler->pullDue(new DateTimeImmutable('2026-10-28 10:00:00'));
        $this->assertEmpty($pulled);
    }

    public function testCancelRemovesAllForSubject(): void
    {
        $scheduler = new SqliteTimeoutScheduler($this->dbPath);
        $order = new Order('ORD-001');
        $due = new DateTimeImmutable('2026-10-28 10:00:00');

        $scheduler->schedule($order, 'pending_payment', 'auto_cancel', $due);
        $scheduler->cancel($order);

        $pulled = $scheduler->pullDue(new DateTimeImmutable('2026-10-28 11:00:00'));
        $this->assertEmpty($pulled);
    }

    public function testMultipleOrdersIndependent(): void
    {
        $scheduler = new SqliteTimeoutScheduler($this->dbPath);
        $order1 = new Order('ORD-001');
        $order2 = new Order('ORD-002');
        $due = new DateTimeImmutable('2026-10-28 10:00:00');

        $scheduler->schedule($order1, 'pending_payment', 'auto_cancel', $due);
        $scheduler->schedule($order2, 'pending_payment', 'auto_cancel', $due);

        $scheduler->cancel($order1);

        $pulled = $scheduler->pullDue(new DateTimeImmutable('2026-10-28 10:01:00'));
        $this->assertCount(1, $pulled);
        $this->assertSame('ORD-002', $pulled[0]->getSubject()->getId());
    }

    public function testPersistsAcrossInstances(): void
    {
        $scheduler1 = new SqliteTimeoutScheduler($this->dbPath);
        $order = new Order('ORD-001');
        $due = new DateTimeImmutable('2026-10-28 10:00:00');
        $scheduler1->schedule($order, 'pending_payment', 'auto_cancel', $due);

        $scheduler2 = new SqliteTimeoutScheduler($this->dbPath);
        $pulled = $scheduler2->pullDue(new DateTimeImmutable('2026-10-28 10:01:00'));
        $this->assertCount(1, $pulled);
        $this->assertSame('ORD-001', $pulled[0]->getSubject()->getId());
    }

    public function testAllReturnsPendingTimeouts(): void
    {
        $scheduler = new SqliteTimeoutScheduler($this->dbPath);
        $order1 = new Order('ORD-001');
        $order2 = new Order('ORD-002');

        $scheduler->schedule($order1, 'pending_payment', 'auto_cancel', new DateTimeImmutable('2026-10-28 10:00:00'));
        $scheduler->schedule($order2, 'new', 'auto_expire', new DateTimeImmutable('2026-10-28 12:00:00'));

        $all = $scheduler->all();
        $this->assertCount(2, $all);
    }

    public function testPullDueOrdersByTime(): void
    {
        $scheduler = new SqliteTimeoutScheduler($this->dbPath);
        $order1 = new Order('ORD-001');
        $order2 = new Order('ORD-002');

        $scheduler->schedule($order2, 'pending_payment', 'auto_cancel', new DateTimeImmutable('2026-10-28 10:30:00'));
        $scheduler->schedule($order1, 'pending_payment', 'auto_cancel', new DateTimeImmutable('2026-10-28 10:00:00'));

        $pulled = $scheduler->pullDue(new DateTimeImmutable('2026-10-28 11:00:00'));
        $this->assertCount(2, $pulled);
        $this->assertSame('ORD-001', $pulled[0]->getSubject()->getId());
        $this->assertSame('ORD-002', $pulled[1]->getSubject()->getId());
    }

    public function testDeleteByOrderId(): void
    {
        $scheduler = new SqliteTimeoutScheduler($this->dbPath);
        $order = new Order('ORD-001');
        $scheduler->schedule($order, 'pending_payment', 'auto_cancel', new DateTimeImmutable('2026-10-28 10:00:00'));

        $scheduler->deleteByOrderId('ORD-001');

        $this->assertEmpty($scheduler->all());
    }

    public function testPendingForOrder(): void
    {
        $scheduler = new SqliteTimeoutScheduler($this->dbPath);
        $order = new Order('ORD-001');
        $scheduler->schedule($order, 'pending_payment', 'auto_cancel', new DateTimeImmutable('2026-10-28 10:30:00'));

        $pending = $scheduler->pendingForOrder('ORD-001');
        $this->assertCount(1, $pending);
        $this->assertSame('auto_cancel', $pending[0]['event']);

        $this->assertEmpty($scheduler->pendingForOrder('ORD-999'));
    }
}
