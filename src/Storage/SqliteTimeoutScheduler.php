<?php

declare(strict_types=1);

namespace Poc\OrderStateMachine\Storage;

use DateTimeImmutable;
use PDO;
use Poc\OrderStateMachine\Entity\Order;
use Tlab\StateMachine\Scheduler\ScheduledTimeout;
use Tlab\StateMachine\Scheduler\TimeoutScheduler;

class SqliteTimeoutScheduler implements TimeoutScheduler
{
    private PDO $pdo;

    public function __construct(string $dbPath)
    {
        $this->pdo = new PDO("sqlite:{$dbPath}");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS scheduled_timeouts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id TEXT NOT NULL,
                state TEXT NOT NULL,
                event TEXT NOT NULL,
                due_at TEXT NOT NULL
            )'
        );
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
