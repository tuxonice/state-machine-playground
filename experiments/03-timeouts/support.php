<?php

declare(strict_types=1);

namespace Experiment03;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Tlab\StateMachine\Conditions\ConditionInterface;
use Tlab\StateMachine\Scheduler\ScheduledTimeout;
use Tlab\StateMachine\Scheduler\TimeoutScheduler;
use Tlab\StateMachine\Storage\StateStorageInterface;

/**
 * Storage decorator that injects the faults a worker meets in production:
 * a slow read, a "poison" order whose write always fails, and a hard crash after N writes.
 */
final class FaultStorage implements StateStorageInterface
{
    public int $writes = 0;

    public function __construct(
        private StateStorageInterface $inner,
        private int $crashAfterWrites = 0,
        private string $poisonOrderId = '',
        private int $readDelayUs = 0,
    ) {
    }

    public function read(object $subject): ?string
    {
        $state = $this->inner->read($subject);
        if ($this->readDelayUs > 0) {
            usleep($this->readDelayUs);
        }

        return $state;
    }

    public function write(object $subject, string $state): void
    {
        if ($this->poisonOrderId !== '' && $subject->getId() === $this->poisonOrderId) {
            throw new RuntimeException("write failed for {$subject->getId()}");
        }
        $this->inner->write($subject, $state);
        $this->writes++;
        if ($this->crashAfterWrites > 0 && $this->writes >= $this->crashAfterWrites) {
            exit(137); // simulates SIGKILL / OOM / deploy: no finally blocks, no output
        }
    }
}

/**
 * Lease-based timeout store over the same table the playground uses.
 * claim() hands out due rows and marks them leased; a row is deleted only by ack() after the work is done.
 * A crashed worker's rows become claimable again when the lease expires; a row that keeps failing is
 * dead-lettered after MAX_ATTEMPTS instead of blocking the others.
 */
final class LeasingScheduler implements TimeoutScheduler
{
    public const MAX_ATTEMPTS = 3;

    private PDO $pdo;

    public function __construct(string $dbPath)
    {
        $this->pdo = new PDO("sqlite:{$dbPath}");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $columns = array_column($this->pdo->query('PRAGMA table_info(scheduled_timeouts)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('claimed_until', $columns, true)) {
            $this->pdo->exec('ALTER TABLE scheduled_timeouts ADD COLUMN claimed_until TEXT NULL');
        }
        if (!in_array('attempts', $columns, true)) {
            $this->pdo->exec('ALTER TABLE scheduled_timeouts ADD COLUMN attempts INTEGER NOT NULL DEFAULT 0');
        }
    }

    public function schedule(object $subject, string $state, string $event, DateTimeImmutable $dueAt): void
    {
        $this->pdo->prepare('INSERT INTO scheduled_timeouts (order_id, state, event, due_at) VALUES (?, ?, ?, ?)')
            ->execute([$subject->getId(), $state, $event, $dueAt->format('Y-m-d H:i:s')]);
    }

    public function cancel(object $subject): void
    {
        $this->pdo->prepare('DELETE FROM scheduled_timeouts WHERE order_id = ?')->execute([$subject->getId()]);
    }

    public function pullDue(DateTimeImmutable $now): array
    {
        throw new RuntimeException('use claim()/ack(): pullDue() deletes before the work is done');
    }

    /** @return list<array{id: int, order_id: string, state: string, event: string}> */
    public function claim(DateTimeImmutable $now, int $limit, int $leaseSeconds): array
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, order_id, state, event FROM scheduled_timeouts
                 WHERE due_at <= :now AND attempts < :max AND (claimed_until IS NULL OR claimed_until < :now)
                 ORDER BY due_at ASC LIMIT :limit'
            );
            $stmt->bindValue('now', $now->format('Y-m-d H:i:s'));
            $stmt->bindValue('max', self::MAX_ATTEMPTS, PDO::PARAM_INT);
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $until = $now->modify("+{$leaseSeconds} seconds")->format('Y-m-d H:i:s');
            $upd = $this->pdo->prepare('UPDATE scheduled_timeouts SET claimed_until = ?, attempts = attempts + 1 WHERE id = ?');
            foreach ($rows as $row) {
                $upd->execute([$until, $row['id']]);
            }
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }

        return $rows;
    }

    public function ack(int $id): void
    {
        $this->pdo->prepare('DELETE FROM scheduled_timeouts WHERE id = ?')->execute([$id]);
    }
}

/** A guard that never passes, to show what happens to a timeout whose condition fails when it is due. */
final class NeverCondition implements ConditionInterface
{
    public function check(array $data): bool
    {
        return false;
    }
}
