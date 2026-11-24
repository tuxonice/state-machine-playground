<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tlab\StatemachinePlayground\Bootstrap;
use Tlab\StatemachinePlayground\Entity\Order;
use Tlab\StatemachinePlayground\Storage\SqliteStateStorage;
use Tlab\StatemachinePlayground\Storage\SqliteTimeoutScheduler;
use Tlab\StateMachine\Exceptions\TimeoutProcessingException;
use Tlab\StateMachine\Storage\StateStorageInterface;

/**
 * A timeout whose processing fails must not be lost: it stays in SQLite and a later run applies it.
 */
class SqliteTimeoutRetryTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'sm_retry_') . '.sqlite';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testAFailedTimeoutIsRetriedOnceItsLeaseExpires(): void
    {
        $storage = new class (new SqliteStateStorage($this->dbPath, 'order')) implements StateStorageInterface {
            public ?string $failFor = null;

            public function __construct(private SqliteStateStorage $inner)
            {
            }

            public function read(object $subject): ?string
            {
                return $this->inner->read($subject);
            }

            public function write(object $subject, string $state): void
            {
                if ($subject->getId() === $this->failFor) {
                    throw new RuntimeException('database error');
                }
                $this->inner->write($subject, $state);
            }
        };
        $scheduler = new SqliteTimeoutScheduler($this->dbPath, leaseSeconds: 60);
        $runner = Bootstrap::createRunner(scheduler: $scheduler, storage: $storage);
        $runner->applyTo(new Order('ORD-1'), 'place_order'); // auto_cancel due in 30 minutes
        $runner->applyTo(new Order('ORD-2'), 'place_order');
        $due = new DateTimeImmutable('+31 minutes');

        $storage->failFor = 'ORD-2';
        try {
            $runner->processTimeouts($due);
            $this->fail('the failure should be reported');
        } catch (TimeoutProcessingException $e) {
            $this->assertCount(1, $e->getFailures());
        }
        $this->assertSame('cancelled', $storage->read(new Order('ORD-1')));
        $this->assertSame('pending_payment', $storage->read(new Order('ORD-2')));

        $storage->failFor = null; // the database is back
        $this->assertSame([], $runner->processTimeouts($due), 'still leased to the run that failed');

        $results = $runner->processTimeouts($due->modify('+61 seconds'));

        $this->assertCount(1, $results);
        $this->assertSame('cancelled', $storage->read(new Order('ORD-2')));
        $this->assertSame([], $scheduler->all());
    }
}
