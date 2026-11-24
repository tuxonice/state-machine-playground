<?php

declare(strict_types=1);

namespace Experiment04;

use PDO;
use Tlab\StateMachine\Storage\StateStorageInterface;

/**
 * One row per order: its definition version is written once at creation and never updated;
 * only the state moves. Shared by all runners, whatever version they run.
 */
final class VersionedStore implements StateStorageInterface
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE orders (order_id TEXT PRIMARY KEY, version INTEGER NOT NULL, state TEXT NOT NULL)');
    }

    public function create(string $orderId, int $version, string $initialState): void
    {
        $this->pdo->prepare('INSERT INTO orders (order_id, version, state) VALUES (?, ?, ?)')->execute([$orderId, $version, $initialState]);
    }

    public function versionOf(string $orderId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT version FROM orders WHERE order_id = ?');
        $stmt->execute([$orderId]);
        $v = $stmt->fetchColumn();

        return $v === false ? null : (int) $v;
    }

    public function read(object $subject): ?string
    {
        $stmt = $this->pdo->prepare('SELECT state FROM orders WHERE order_id = ?');
        $stmt->execute([$subject->getId()]);
        $s = $stmt->fetchColumn();

        return $s === false ? null : $s;
    }

    public function write(object $subject, string $state): void
    {
        $this->pdo->prepare('UPDATE orders SET state = ? WHERE order_id = ?')->execute([$state, $subject->getId()]);
    }

    /** @return array<int, array<string, int>> version => state => count */
    public function distribution(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT version, state, COUNT(*) c FROM orders GROUP BY version, state') as $row) {
            $out[(int) $row['version']][$row['state']] = (int) $row['c'];
        }

        return $out;
    }
}
