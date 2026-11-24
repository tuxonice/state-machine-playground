<?php

declare(strict_types=1);

namespace Experiment08;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Tlab\StateMachine\Scheduler\ScheduledTimeout;
use Tlab\StateMachine\Scheduler\TimeoutScheduler;

/**
 * Timeout store on the EntityManager's own DBAL connection, so it can share a transaction with the entity.
 * pullDue() re-hydrates the real, managed entity (a bare id object would not carry the state).
 * It keeps the library's semantics: due rows are deleted when pulled (see experiment 3).
 */
final class DoctrineTimeoutScheduler implements TimeoutScheduler
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public static function createTable(EntityManagerInterface $em): void
    {
        $em->getConnection()->executeStatement(
            'CREATE TABLE IF NOT EXISTS timeouts (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id TEXT NOT NULL, state TEXT NOT NULL, event TEXT NOT NULL, due_at TEXT NOT NULL)'
        );
        $em->getConnection()->executeStatement('CREATE INDEX IF NOT EXISTS idx_timeouts_order ON timeouts (order_id)');
        $em->getConnection()->executeStatement('CREATE INDEX IF NOT EXISTS idx_timeouts_due ON timeouts (due_at)');
    }

    public function schedule(object $subject, string $state, string $event, DateTimeImmutable $dueAt): void
    {
        $this->em->getConnection()->insert('timeouts', [
            'order_id' => $subject->getId(), 'state' => $state, 'event' => $event, 'due_at' => $dueAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function cancel(object $subject): void
    {
        $this->em->getConnection()->delete('timeouts', ['order_id' => $subject->getId()]);
    }

    public function pullDue(DateTimeImmutable $now): array
    {
        $conn = $this->em->getConnection();
        $rows = $conn->fetchAllAssociative('SELECT id, order_id, state, event, due_at FROM timeouts WHERE due_at <= ? ORDER BY due_at', [$now->format('Y-m-d H:i:s')]);
        $out = [];
        foreach ($rows as $row) {
            $conn->delete('timeouts', ['id' => $row['id']]);
            $entity = $this->em->find(OrderEntity::class, $row['order_id']);
            if ($entity !== null) {
                $out[] = new ScheduledTimeout($entity, $row['state'], $row['event'], new DateTimeImmutable($row['due_at']));
            }
        }

        return $out;
    }
}
