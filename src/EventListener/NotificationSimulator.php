<?php

declare(strict_types=1);

namespace Tlab\StatemachinePlayground\EventListener;

use Tlab\StateMachine\Events\AfterTransition;

class NotificationSimulator
{
    private const NOTIFICATION_TARGETS = [
        'confirmed' => 'Order confirmed — payment received',
        'shipped' => 'Order shipped — your package is on the way',
        'delivered' => 'Order delivered — enjoy your purchase',
        'refunded' => 'Refund issued — amount will be returned to your account',
    ];

    /** @var array<int, array{target: string, message: string, timestamp: string}> */
    private array $notifications = [];

    public function onAfterTransition(AfterTransition $event): void
    {
        if (!isset(self::NOTIFICATION_TARGETS[$event->target])) {
            return;
        }

        $this->notifications[] = [
            'target' => $event->target,
            'message' => self::NOTIFICATION_TARGETS[$event->target],
            'timestamp' => date('H:i:s'),
        ];
    }

    /** @return array<int, array{target: string, message: string, timestamp: string}> */
    public function getNotifications(): array
    {
        return $this->notifications;
    }
}
