<?php

declare(strict_types=1);

namespace Poc\OrderStateMachine\Storage;

use PDO;
use Poc\OrderStateMachine\Entity\Order;
use Tlab\StateMachine\Storage\StateStorageInterface;

class SqliteStateStorage implements StateStorageInterface
{
    private PDO $pdo;
    private string $machine;

    public function __construct(string $dbPath, string $machine = '')
    {
        $this->machine = $machine;
        $this->pdo = new PDO("sqlite:{$dbPath}");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS order_states (
                order_id TEXT PRIMARY KEY,
                machine TEXT NOT NULL DEFAULT \'\',
                state TEXT NOT NULL,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS order_transitions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id TEXT NOT NULL,
                from_state TEXT NOT NULL,
                event TEXT NOT NULL,
                to_state TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    public function read(object $subject): ?string
    {
        /** @var Order $subject */
        $stmt = $this->pdo->prepare('SELECT state FROM order_states WHERE order_id = :id');
        $stmt->execute(['id' => $subject->getId()]);
        $state = $stmt->fetchColumn();

        if ($state === false) {
            return null;
        }

        $subject->setState($state);
        return $state;
    }

    public function write(object $subject, string $state): void
    {
        /** @var Order $subject */
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_states (order_id, machine, state, updated_at) VALUES (:id, :machine, :state, :now)
             ON CONFLICT(order_id) DO UPDATE SET state = :state, updated_at = :now'
        );
        $stmt->execute([
            'id' => $subject->getId(),
            'machine' => $this->machine,
            'state' => $state,
            'now' => date('Y-m-d H:i:s'),
        ]);
        $subject->setState($state);
    }

    /** @return array<string, string> order_id => state */
    public function listOrders(): array
    {
        $stmt = $this->pdo->prepare('SELECT order_id, state FROM order_states WHERE machine = :machine ORDER BY updated_at DESC');
        $stmt->execute(['machine' => $this->machine]);
        $orders = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $orders[$row['order_id']] = $row['state'];
        }
        return $orders;
    }

    public function deleteOrder(string $orderId): void
    {
        $this->pdo->prepare('DELETE FROM order_transitions WHERE order_id = :id')->execute(['id' => $orderId]);
        $this->pdo->prepare('DELETE FROM order_states WHERE order_id = :id')->execute(['id' => $orderId]);
    }

    public function saveTransition(string $orderId, string $from, string $event, string $to): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_transitions (order_id, from_state, event, to_state, created_at)
             VALUES (:id, :from, :event, :to, :now)'
        );
        $stmt->execute([
            'id' => $orderId,
            'from' => $from,
            'event' => $event,
            'to' => $to,
            'now' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<array{from_state: string, event: string, to_state: string, created_at: string}> */
    public function getTransitions(string $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT from_state, event, to_state, created_at FROM order_transitions WHERE order_id = :id ORDER BY id ASC'
        );
        $stmt->execute(['id' => $orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
