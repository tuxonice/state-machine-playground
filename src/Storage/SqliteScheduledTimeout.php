<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\Storage;

use DateTimeImmutable;
use Tlab\StateMachine\Scheduler\ScheduledTimeout;

/**
 * A timeout claimed from SQLite, carrying its row id so it can be acknowledged.
 */
final class SqliteScheduledTimeout extends ScheduledTimeout
{
    public function __construct(
        private readonly int $id,
        object $subject,
        string $state,
        string $event,
        DateTimeImmutable $dueAt,
    ) {
        parent::__construct($subject, $state, $event, $dueAt);
    }

    public function getId(): int
    {
        return $this->id;
    }
}
