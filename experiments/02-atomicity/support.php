<?php

declare(strict_types=1);

namespace Experiment02;

use DateTimeImmutable;
use PDO;
use Psr\EventDispatcher\EventDispatcherInterface;
use Tlab\StateMachine\Commands\CommandInterface;
use Tlab\StateMachine\Events\AfterTransition;
use Tlab\StateMachine\Events\BeforeTransition;
use Tlab\StateMachine\Scheduler\TimeoutScheduler;
use Tlab\StateMachine\Storage\StateStorageInterface;
use RuntimeException;
use Tlab\StateMachine\TransitionContext;

/** Shared test context: the DB connection, what was observed, and which faults are armed. */
final class Ctx
{
    public static PDO $pdo;
    /** @var list<string> things that cannot be rolled back: events seen and notifications "sent" */
    public static array $external = [];
    public static ?string $failCommand = null;       // 'charge' | 'reserve'
    public static ?string $failWriteTo = null;       // state name whose write fails
    public static ?string $failAfterListenerOn = null; // target state whose AfterTransition listener throws
    public static bool $failBeforeListener = false;
    public static bool $failSchedule = false;

    public static function reset(): void
    {
        self::$pdo = new PDO('sqlite::memory:');
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->exec('CREATE TABLE states (id TEXT PRIMARY KEY, state TEXT NOT NULL)');
        self::$pdo->exec('CREATE TABLE timeouts (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id TEXT, state TEXT, event TEXT)');
        self::$pdo->exec('CREATE TABLE effects (id INTEGER PRIMARY KEY AUTOINCREMENT, what TEXT)');
        self::$external = [];
        self::$failCommand = self::$failWriteTo = self::$failAfterListenerOn = null;
        self::$failBeforeListener = self::$failSchedule = false;
    }
}

/** Models a payment gateway charge: the effect is recorded in the DB ledger. */
final class ChargeCommand implements CommandInterface
{
    public function run(TransitionContext $context): void
    {
        if (Ctx::$failCommand === 'charge') {
            throw new RuntimeException('charge command failed before doing anything');
        }
        Ctx::$pdo->exec("INSERT INTO effects (what) VALUES ('charged')");
    }
}

final class ReserveStockCommand implements CommandInterface
{
    public function run(TransitionContext $context): void
    {
        if (Ctx::$failCommand === 'reserve') {
            throw new RuntimeException('stock service down');
        }
        Ctx::$pdo->exec("INSERT INTO effects (what) VALUES ('stock reserved')");
    }
}

final class PdoStorage implements StateStorageInterface
{
    public function read(object $subject): ?string
    {
        $stmt = Ctx::$pdo->prepare('SELECT state FROM states WHERE id = ?');
        $stmt->execute([$subject->getId()]);
        $state = $stmt->fetchColumn();

        return $state === false ? null : $state;
    }

    public function write(object $subject, string $state): void
    {
        if (Ctx::$failWriteTo === $state) {
            throw new RuntimeException("storage write to '{$state}' failed");
        }
        Ctx::$pdo->prepare('INSERT INTO states (id, state) VALUES (?, ?) ON CONFLICT(id) DO UPDATE SET state = excluded.state')
            ->execute([$subject->getId(), $state]);
    }
}

final class PdoScheduler implements TimeoutScheduler
{
    public function schedule(object $subject, string $state, string $event, DateTimeImmutable $dueAt): void
    {
        if (Ctx::$failSchedule) {
            throw new RuntimeException('scheduler unavailable');
        }
        Ctx::$pdo->prepare('INSERT INTO timeouts (order_id, state, event) VALUES (?, ?, ?)')
            ->execute([$subject->getId(), $state, $event]);
    }

    public function cancel(object $subject): void
    {
        Ctx::$pdo->prepare('DELETE FROM timeouts WHERE order_id = ?')->execute([$subject->getId()]);
    }

    public function pullDue(DateTimeImmutable $now): array
    {
        return [];
    }
}

/** PSR-14 dispatcher that records what listeners saw and can throw, like a failing mail service. */
final class Dispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        if ($event instanceof BeforeTransition) {
            Ctx::$external[] = "before {$event->source}->{$event->target}";
            if (Ctx::$failBeforeListener) {
                throw new RuntimeException('before-listener failed');
            }
        }
        if ($event instanceof AfterTransition) {
            Ctx::$external[] = "after {$event->source}->{$event->target}";
            Ctx::$external[] = "email: order is now {$event->target}";
            if (Ctx::$failAfterListenerOn === $event->target) {
                throw new RuntimeException('mail service down in after-listener');
            }
        }

        return $event;
    }
}
