<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\Storage;

use DateTimeImmutable;
use PDO;
use Tlab\StatemachinePlayground\Entity\Order;
use InvalidArgumentException;
use Throwable;
use Tlab\StateMachine\Scheduler\AcknowledgingTimeoutScheduler;
use Tlab\StateMachine\Scheduler\ScheduledTimeout;

/**
 * Timeouts in SQLite. processTimeouts() claims due rows with a lease and deletes each one only once it has been
 * dealt with, so a failing timeout or a worker that dies is retried after the lease instead of being lost, and
 * two workers do not claim the same row.
 */
class SqliteTimeoutScheduler implements AcknowledgingTimeoutScheduler
{
    private PDO $pdo;

    /**
     * @param int $leaseSeconds how long a claimed timeout is hidden from other runs before it can be claimed again
     * @param int $batchSize    the most timeouts one claimDue() returns
     * @param int $maxAttempts  claims after which a timeout that was never acknowledged is no longer claimed
     *                          (it stays in the table for inspection)
     */
    public function __construct(
        string $dbPath,
        private readonly int $leaseSeconds = 60,
        private readonly int $batchSize = 100,
        private readonly int $maxAttempts = 3,
    ) {
        $this->pdo = new PDO("sqlite:{$dbPath}");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS scheduled_timeouts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id TEXT NOT NULL,
                state TEXT NOT NULL,
                event TEXT NOT NULL,
                due_at TEXT NOT NULL,
                claimed_until TEXT NULL,
                attempts INTEGER NOT NULL DEFAULT 0
            )'
        );
        // Databases created before timeouts were claimed lack these columns.
        $columns = array_column($this->pdo->query('PRAGMA table_info(scheduled_timeouts)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('claimed_until', $columns, true)) {
            $this->pdo->exec('ALTER TABLE scheduled_timeouts ADD COLUMN claimed_until TEXT NULL');
        }
        if (!in_array('attempts', $columns, true)) {
            $this->pdo->exec('ALTER TABLE scheduled_timeouts ADD COLUMN attempts INTEGER NOT NULL DEFAULT 0');
        }
        // cancel() runs on every transition and claimDue() on every worker run: neither may scan the table.
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_scheduled_timeouts_order ON scheduled_timeouts (order_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_scheduled_timeouts_due ON scheduled_timeouts (due_at)');
    }

    /** @return SqliteScheduledTimeout[] */
    public function claimDue(DateTimeImmutable $now): array
    {
        $at = $now->format('Y-m-d H:i:s');
        // IMMEDIATE takes the write lock up front, so two workers cannot select the same rows.
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, order_id, state, event, due_at FROM scheduled_timeouts
                 WHERE due_at <= :now AND (claimed_until IS NULL OR claimed_until < :now) AND attempts < :max
                 ORDER BY due_at ASC LIMIT :limit'
            );
            $stmt->bindValue('now', $at);
            $stmt->bindValue('max', $this->maxAttempts, PDO::PARAM_INT);
            $stmt->bindValue('limit', $this->batchSize, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $lease = $this->pdo->prepare(
                'UPDATE scheduled_timeouts SET claimed_until = :until, attempts = attempts + 1 WHERE id = :id'
            );
            $until = $now->modify("+{$this->leaseSeconds} seconds")->format('Y-m-d H:i:s');
            foreach ($rows as $row) {
                $lease->execute(['until' => $until, 'id' => $row['id']]);
            }
            $this->pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }

        return array_map(
            fn(array $row) => new SqliteScheduledTimeout(
                (int) $row['id'],
                new Order($row['order_id']),
                $row['state'],
                $row['event'],
                new DateTimeImmutable($row['due_at']),
            ),
            $rows
        );
    }

    public function acknowledge(ScheduledTimeout $timeout): void
    {
        if (!$timeout instanceof SqliteScheduledTimeout) {
            throw new InvalidArgumentException('Only timeouts returned by claimDue() can be acknowledged');
        }

        $this->pdo->prepare('DELETE FROM scheduled_timeouts WHERE id = :id')->execute(['id' => $timeout->getId()]);
    }

    public function schedule(object $subject, string $state, string $event, DateTimeImmutable $dueAt): void
    {
        /** @var Order $subject */
        $stmt = $this->pdo->prepare(
            'INSERT INTO scheduled_timeouts (order_id, state, event, due_at) VALUES (:id, :state, :event, :due)'
        );
        $stmt->execute([
            'id' => $subject->getId(),
            'state' => $state,
            'event' => $event,
            'due' => $dueAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function cancel(object $subject): void
    {
        /** @var Order $subject */
        $stmt = $this->pdo->prepare('DELETE FROM scheduled_timeouts WHERE order_id = :id');
        $stmt->execute(['id' => $subject->getId()]);
    }

    /** @return ScheduledTimeout[] */
    public function pullDue(DateTimeImmutable $now): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, order_id, state, event, due_at FROM scheduled_timeouts WHERE due_at <= :now ORDER BY due_at ASC'
        );
        $stmt->execute(['now' => $now->format('Y-m-d H:i:s')]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return [];
        }

        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->prepare("DELETE FROM scheduled_timeouts WHERE id IN ({$placeholders})")->execute($ids);

        return array_map(
            fn(array $row) => new ScheduledTimeout(
                new Order($row['order_id']),
                $row['state'],
                $row['event'],
                new DateTimeImmutable($row['due_at']),
            ),
            $rows
        );
    }

    /** @return ScheduledTimeout[] */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT order_id, state, event, due_at FROM scheduled_timeouts ORDER BY due_at ASC');
        return array_map(
            fn(array $row) => new ScheduledTimeout(
                new Order($row['order_id']),
                $row['state'],
                $row['event'],
                new DateTimeImmutable($row['due_at']),
            ),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function deleteByOrderId(string $orderId): void
    {
        $this->pdo->prepare('DELETE FROM scheduled_timeouts WHERE order_id = :id')->execute(['id' => $orderId]);
    }

    /** @return list<array{state: string, event: string, due_at: string}> */
    public function pendingForOrder(string $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT state, event, due_at FROM scheduled_timeouts WHERE order_id = :id ORDER BY due_at ASC'
        );
        $stmt->execute(['id' => $orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
