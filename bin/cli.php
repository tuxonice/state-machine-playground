#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Poc\OrderStateMachine\Bootstrap;
use Poc\OrderStateMachine\Entity\Order;
use Poc\OrderStateMachine\EventListener\NotificationSimulator;
use Poc\OrderStateMachine\EventListener\TransitionLogger;
use Tlab\StateMachine\Scheduler\InMemoryTimeoutScheduler;
use Tlab\StateMachine\TransitionStatus;

$configPath = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--config' && isset($argv[$i + 1])) {
        $configPath = $argv[$i + 1];
    }
}

$logger = new TransitionLogger();
$notifier = new NotificationSimulator();
$scheduler = new InMemoryTimeoutScheduler();
$runner = Bootstrap::createRunner($configPath, $logger, $notifier, $scheduler);
$order = new Order('ORD-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT));

echo "\n\033[1m=== Order State Machine CLI Demo ===\033[0m\n";
echo "\033[90mOrder: {$order->getId()} (using StatefulInterface + PSR-14 events)\033[0m\n\n";

while (true) {
    $currentState = $order->getState() ?? $runner->getStateMachine()->getCurrentState() ?? 'new';
    $events = $runner->availableManualEvents($currentState);

    echo "\033[32mCurrent state: \033[1m{$currentState}\033[0m\n\n";

    if (empty($events)) {
        echo "\033[33mOrder reached terminal state. No more transitions available.\033[0m\n";
        break;
    }

    echo "Available events:\n";
    foreach ($events as $i => $event) {
        echo "  \033[36m[" . ($i + 1) . "]\033[0m {$event}\n";
    }
    $pending = $scheduler->all();
    if (!empty($pending)) {
        echo "\n\033[33m⏱  Pending timeouts:\033[0m\n";
        foreach ($pending as $timeout) {
            echo "    {$timeout->getEvent()} (due: {$timeout->getDueAt()->format('H:i:s')})\n";
        }
        echo "\n";
    }

    echo "  \033[36m[t]\033[0m Simulate time passing (trigger timeouts)\n";
    echo "  \033[36m[d]\033[0m Show diagram\n";
    echo "  \033[36m[l]\033[0m Show event log\n";
    echo "  \033[36m[q]\033[0m Quit\n\n";

    $input = readline('Select an option: ');

    if ($input === false || strtolower(trim($input)) === 'q' || strtolower(trim($input)) === 'quit') {
        echo "\nBye!\n";
        break;
    }

    if (strtolower(trim($input)) === 'd' || strtolower(trim($input)) === 'diagram') {
        echo "\n\033[1mMermaid Diagram:\033[0m\n";
        echo $runner->generateMarkdownDiagram() . "\n";
        continue;
    }

    if (strtolower(trim($input)) === 'l' || strtolower(trim($input)) === 'log') {
        $entries = $logger->getEntries();
        $notifications = $notifier->getNotifications();

        if (empty($entries) && empty($notifications)) {
            echo "\n\033[90mNo events logged yet.\033[0m\n\n";
        } else {
            if (!empty($entries)) {
                echo "\n\033[1mTransition Log:\033[0m\n";
                foreach ($entries as $entry) {
                    if ($entry['type'] === 'after_transition') {
                        echo "  \033[32m✓\033[0m [{$entry['timestamp']}] {$entry['source']} → \033[1m{$entry['event']}\033[0m → {$entry['target']}\n";
                    } else {
                        echo "  \033[31m✗\033[0m [{$entry['timestamp']}] {$entry['source']} → \033[1m{$entry['event']}\033[0m (blocked)\n";
                    }
                }
            }
            if (!empty($notifications)) {
                echo "\n\033[1mNotifications:\033[0m\n";
                foreach ($notifications as $notif) {
                    echo "  \033[33m📧\033[0m [{$notif['timestamp']}] {$notif['message']}\n";
                }
            }
            echo "\n";
        }
        continue;
    }

    if (strtolower(trim($input)) === 't' || strtolower(trim($input)) === 'time') {
        $minutes = readline('Skip forward how many minutes? [31]: ');
        $minutes = (int) (trim((string) $minutes) ?: '31');
        $future = new DateTimeImmutable("+{$minutes} minutes");
        echo "\n\033[90m⏱  Simulating {$minutes} minutes passing (now: {$future->format('H:i:s')})...\033[0m\n";

        $results = $runner->processTimeouts($future);
        if (empty($results)) {
            echo "\033[90m  No timeouts fired.\033[0m\n\n";
        } else {
            foreach ($results as $r) {
                if ($r->hasMoved()) {
                    echo "\033[33m  ⏱ Timeout fired: {$r->getState()}\033[0m\n";
                }
            }
            echo "\n";
        }
        continue;
    }

    $choice = (int) trim($input);
    if ($choice < 1 || $choice > count($events)) {
        echo "\n\033[31mInvalid option. Please select a number from the list.\033[0m\n\n";
        continue;
    }

    $event = $events[$choice - 1];
    $data = [];

    if ($event === 'payment_success') {
        $method = readline('Enter payment method (e.g. credit_card): ');
        $data['payment_method'] = trim((string) $method);
        $stock = readline('Stock available? (y/n) [y]: ');
        $data['stock_available'] = strtolower(trim((string) $stock)) !== 'n';
    }

    if ($event === 'cancel') {
        $data['current_state'] = $currentState;
    }

    $result = $runner->applyTo($order, $event, $data);

    echo "\n";
    if ($result->hasMoved()) {
        $firedEvents = $result->getEvents();
        if (count($firedEvents) > 1) {
            echo "\033[32m✓ Transition chain: " . implode(' → ', $firedEvents) . " → {$order->getState()}\033[0m\n";
            echo "\033[90m  (auto-transition via onEnter)\033[0m\n";
        } else {
            echo "\033[32m✓ Transition: {$event} → {$order->getState()}\033[0m\n";
        }

        $notifications = $notifier->getNotifications();
        if (!empty($notifications)) {
            $last = end($notifications);
            echo "\033[33m  📧 {$last['message']}\033[0m\n";
        }
        echo "\n";
    } elseif ($result->getStatus() === TransitionStatus::Blocked) {
        echo "\033[31m✗ Transition blocked: condition not met for '{$event}'\033[0m\n\n";
    } else {
        echo "\033[31m✗ No transition available for '{$event}' from '{$currentState}'\033[0m\n\n";
    }
}
