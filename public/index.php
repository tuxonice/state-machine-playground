<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Poc\OrderStateMachine\Bootstrap;
use Poc\OrderStateMachine\Entity\Order;
use Poc\OrderStateMachine\EventListener\NotificationSimulator;
use Poc\OrderStateMachine\EventListener\TransitionLogger;
use Poc\OrderStateMachine\Storage\SqliteStateStorage;
use Poc\OrderStateMachine\Storage\SqliteTimeoutScheduler;
use Tlab\StateMachine\Reader\DefinitionReader;
use Tlab\StateMachine\StateMachineRunner;
use Tlab\StateMachine\TransitionStatus;

session_start();

// Discover available configs
$configDir = __DIR__ . '/../config';
$configs = [];
foreach (glob($configDir . '/*-state-machine.json') as $file) {
    $json = json_decode(file_get_contents($file), true);
    $configs[basename($file)] = $json['name'] ?? basename($file, '.json');
}

// Select config
$selectedConfig = $_GET['config'] ?? $_SESSION['selected_config'] ?? array_key_first($configs);
if (!isset($configs[$selectedConfig])) {
    $selectedConfig = array_key_first($configs);
}
$_SESSION['selected_config'] = $selectedConfig;
$machineName = $configs[$selectedConfig];
$configPath = $configDir . '/' . $selectedConfig;

// Set up persistence
$dbPath = __DIR__ . '/../data/orders.sqlite';
if (!is_dir(dirname($dbPath))) {
    mkdir(dirname($dbPath), 0777, true);
}

$storage = new SqliteStateStorage($dbPath, $machineName);
$scheduler = new SqliteTimeoutScheduler($dbPath);
$logger = new TransitionLogger();
$notifier = new NotificationSimulator();
$runner = Bootstrap::createRunner(
    configPath: $configPath,
    transitionLogger: $logger,
    notificationSimulator: $notifier,
    scheduler: $scheduler,
    storage: $storage,
);
$stateMachine = $runner->getStateMachine();
$initialState = $stateMachine->getCurrentState() ?? 'new';

$message = null;
$messageType = null;
$selectedOrderId = $_SESSION['selected_order'] ?? null;

// Handle config switch — clear selection
if (isset($_GET['config']) && $_GET['config'] !== ($_SESSION['prev_config'] ?? null)) {
    $selectedOrderId = null;
    $_SESSION['selected_order'] = null;
    $_SESSION['event_log'] = [];
    $_SESSION['notifications'] = [];
}
$_SESSION['prev_config'] = $selectedConfig;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_order'])) {
        $prefix = strtoupper(substr($machineName, 0, 3));
        $newId = $prefix . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $newOrder = new Order($newId);
        $storage->write($newOrder, $initialState);
        $runner->scheduleTimeouts($newOrder);
        $selectedOrderId = $newId;
        $_SESSION['selected_order'] = $newId;
        $_SESSION['event_log'] = [];
        $_SESSION['notifications'] = [];
        $message = "Created {$newId}";
        $messageType = 'success';
    }

    if (isset($_POST['select_order'])) {
        $selectedOrderId = $_POST['select_order'];
        $_SESSION['selected_order'] = $selectedOrderId;
        $_SESSION['event_log'] = [];
        $_SESSION['notifications'] = [];
    }

    if (isset($_POST['delete_order']) && isset($_POST['delete_id'])) {
        $deleteId = $_POST['delete_id'];
        $storage->deleteOrder($deleteId);
        $scheduler->deleteByOrderId($deleteId);
        if ($selectedOrderId === $deleteId) {
            $selectedOrderId = null;
            $_SESSION['selected_order'] = null;
        }
        $message = "Deleted {$deleteId}";
        $messageType = 'info';
    }

    if (isset($_POST['reset_all'])) {
        foreach ($storage->listOrders() as $id => $_) {
            $storage->deleteOrder($id);
            $scheduler->deleteByOrderId($id);
        }
        $selectedOrderId = null;
        $_SESSION['selected_order'] = null;
        $_SESSION['event_log'] = [];
        $_SESSION['notifications'] = [];
        $message = 'All entries deleted';
        $messageType = 'info';
    }
}

// Load current entity if selected
$order = null;
$currentState = null;
if ($selectedOrderId) {
    $order = new Order($selectedOrderId);
    $currentState = $storage->read($order);
    if ($currentState === null) {
        $selectedOrderId = null;
        $_SESSION['selected_order'] = null;
    }
}

// Handle transition and timeout actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $order) {
    if (isset($_POST['simulate_timeout'])) {
        $minutes = max(1, (int) ($_POST['minutes'] ?? 31));
        $future = new DateTimeImmutable("+{$minutes} minutes");
        $from = $order->getState();

        $results = $runner->processTimeouts($future);
        if (empty($results)) {
            $message = "No timeouts fired after simulating {$minutes} minutes";
            $messageType = 'info';
        } else {
            foreach ($results as $r) {
                if ($r->hasMoved()) {
                    $currentState = $storage->read($order);
                    $storage->saveTransition($selectedOrderId, $from, $r->getEvents()[0] . ' (timeout)', $order->getState());
                    $message = "Timeout fired after {$minutes} min &rarr; {$order->getState()}";
                    $messageType = 'warning';
                }
            }
        }

        foreach ($logger->getEntries() as $entry) {
            $_SESSION['event_log'][] = $entry;
        }
        foreach ($notifier->getNotifications() as $notif) {
            $_SESSION['notifications'][] = $notif;
        }
    }

    if (isset($_POST['event'])) {
        $event = $_POST['event'];
        $data = [];

        if (!empty($_POST['payment_method'])) {
            $data['payment_method'] = $_POST['payment_method'];
        }
        if (isset($_POST['stock_available'])) {
            $data['stock_available'] = true;
        }
        $data['current_state'] = $order->getState();

        try {
            $from = $order->getState();
            $result = $runner->applyTo($order, $event, $data);

            if ($result->hasMoved()) {
                $currentState = $order->getState();
                $firedEvents = $result->getEvents();
                $isChain = count($firedEvents) > 1;
                $eventLabel = $isChain ? implode(' > ', $firedEvents) . ' (auto)' : $event;
                $storage->saveTransition($selectedOrderId, $from, $eventLabel, $currentState);

                if ($isChain) {
                    $message = "Chain: " . implode(' &rarr; ', $firedEvents) . " &rarr; {$currentState}";
                } else {
                    $message = "Transition: {$event} &rarr; {$currentState}";
                }
                $messageType = 'success';
            } elseif ($result->getStatus() === TransitionStatus::Blocked) {
                $message = "Transition blocked: condition not met for '{$event}'";
                $messageType = 'warning';
            } else {
                $message = "No transition available for '{$event}'";
                $messageType = 'danger';
            }

            foreach ($logger->getEntries() as $entry) {
                $_SESSION['event_log'][] = $entry;
            }
            foreach ($notifier->getNotifications() as $notif) {
                $_SESSION['notifications'][] = $notif;
            }
        } catch (\Tlab\StateMachine\Exceptions\UnknownEventException $e) {
            $message = "Unknown event: {$event}";
            $messageType = 'danger';
        }
    }
}

$allOrders = $storage->listOrders();
$availableEvents = ($currentState) ? $runner->availableManualEvents($currentState) : [];
$history = $selectedOrderId ? $storage->getTransitions($selectedOrderId) : [];
$eventLog = $_SESSION['event_log'] ?? [];
$notifications = $_SESSION['notifications'] ?? [];
$pendingTimeouts = $selectedOrderId ? $scheduler->pendingForOrder($selectedOrderId) : [];

// View mode (list or board)
$viewMode = $_GET['view'] ?? $_SESSION['view_mode'] ?? 'list';
$_SESSION['view_mode'] = $viewMode;

// Group entries by state for board view
$entriesByState = [];
foreach ($allOrders as $id => $state) {
    $entriesByState[$state][] = $id;
}

// Determine state categories from the config
$allStates = array_map(fn($s) => $s->getName(), $stateMachine->getStates());
$statesWithOutgoing = [];
foreach ($stateMachine->getTransitions() as $t) {
    $statesWithOutgoing[$t->getSource()] = true;
}
$terminalStates = array_filter($allStates, fn($s) => !isset($statesWithOutgoing[$s]));

function getStateBadgeClass(string $state, array $terminalStates): string
{
    if (in_array($state, $terminalStates, true)) return 'bg-secondary';
    return 'bg-primary';
}

// Build Mermaid diagram
$mermaid = "graph TD\n";
foreach ($stateMachine->getStates() as $state) {
    $name = $state->getName();
    $label = str_replace('_', ' ', $name);
    $mermaid .= "    {$name}[\"{$label}\"]\n";
}
$mermaid .= "\n";
foreach ($stateMachine->getTransitions() as $transition) {
    $source = $transition->getSource();
    $target = $transition->getTarget();
    $event = str_replace('_', ' ', $transition->getEvent());
    $mermaid .= "    {$source} -->|\"{$event}\"| {$target}\n";
}
$mermaid .= "\n";
$mermaid .= "    classDef current fill:#198754,stroke:#146c43,color:#fff,stroke-width:3px\n";
$mermaid .= "    classDef terminal fill:#6c757d,stroke:#495057,color:#fff\n";
if ($currentState) {
    $mermaid .= "    class {$currentState} current\n";
}
foreach ($terminalStates as $ts) {
    if ($ts !== $currentState) {
        $mermaid .= "    class {$ts} terminal\n";
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(ucwords(str_replace('_', ' ', $machineName))) ?> State Machine</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .history-log, .event-log { max-height: 300px; overflow-y: auto; }
        .mermaid { display: flex; justify-content: center; }
        .order-list .list-group-item.active { background-color: #198754; border-color: #198754; }
        .board-container { display: flex; gap: 12px; overflow-x: auto; padding-bottom: 8px; min-height: 200px; }
        .board-column { min-width: 180px; max-width: 220px; flex: 1 0 180px; background: #f8f9fa; border-radius: 8px; display: flex; flex-direction: column; }
        .board-column-header { padding: 10px 12px; font-weight: 600; font-size: 0.85rem; border-bottom: 2px solid #dee2e6; display: flex; justify-content: space-between; align-items: center; }
        .board-column-header .badge { font-size: 0.75rem; }
        .board-column-body { padding: 8px; flex: 1; min-height: 60px; }
        .board-card { background: #fff; border: 2px solid transparent; border-radius: 6px; padding: 8px 10px; margin-bottom: 6px; cursor: pointer; box-shadow: 0 1px 2px rgba(0,0,0,0.06); transition: border-color 0.15s, box-shadow 0.15s; font-size: 0.85rem; }
        .board-card:hover { border-color: #0d6efd; box-shadow: 0 2px 6px rgba(13,110,253,0.15); }
        .board-card.selected { border-color: #198754; background: #d1e7dd; }
        .board-column.terminal { background: #e9ecef; }
        .board-column.terminal .board-column-header { color: #6c757d; }
        .board-column.current-state .board-column-header { border-bottom-color: #198754; }
        .view-toggle .btn { font-size: 0.85rem; }
    </style>
</head>
<body class="bg-light">
    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col">
                <h1 class="text-center"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $machineName))) ?> State Machine</h1>
                <p class="text-center text-muted mb-2">
                    Evaluating <code>tuxonice/state-machine</code> &mdash; SQLite persistence + PSR-14 Events
                </p>
                <div class="text-center mb-2">
                    <?php foreach ($configs as $file => $name): ?>
                        <a href="?config=<?= urlencode($file) ?>"
                           class="btn btn-sm <?= $file === $selectedConfig ? 'btn-primary' : 'btn-outline-primary' ?> mx-1">
                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $name))) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="text-center view-toggle">
                    <div class="btn-group btn-group-sm">
                        <a href="?view=list" class="btn <?= $viewMode === 'list' ? 'btn-dark' : 'btn-outline-dark' ?>">List</a>
                        <a href="?view=board" class="btn <?= $viewMode === 'board' ? 'btn-dark' : 'btn-outline-dark' ?>">Board</a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="row mb-3">
            <div class="col">
                <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                    <?= $message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-8 mb-4">
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Entries (<?= count($allOrders) ?>)</h5>
                        <div>
                            <form method="post" class="d-inline">
                                <button type="submit" name="create_order" value="1" class="btn btn-sm btn-success">+ New</button>
                            </form>
                            <?php if (!empty($allOrders)): ?>
                            <form method="post" class="d-inline ms-1">
                                <button type="submit" name="reset_all" value="1" class="btn btn-sm btn-outline-danger"
                                    onclick="return confirm('Delete all entries?')">Delete All</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body p-2">
                        <?php if (empty($allOrders)): ?>
                            <p class="text-muted text-center my-2">No entries yet. Create one to get started.</p>
                        <?php elseif ($viewMode === 'board'): ?>
                            <div class="board-container">
                                <?php foreach ($allStates as $state): ?>
                                    <?php
                                    $isTerminal = in_array($state, $terminalStates, true);
                                    $hasSelected = isset($entriesByState[$state]) && in_array($selectedOrderId, $entriesByState[$state], true);
                                    $columnClass = $isTerminal ? 'terminal' : '';
                                    if ($hasSelected) $columnClass .= ' current-state';
                                    $stateEntries = $entriesByState[$state] ?? [];
                                    ?>
                                    <div class="board-column <?= $columnClass ?>">
                                        <div class="board-column-header">
                                            <span><?= htmlspecialchars(ucwords(str_replace('_', ' ', $state))) ?></span>
                                            <?php if (!empty($stateEntries)): ?>
                                                <span class="badge <?= $isTerminal ? 'bg-secondary' : 'bg-primary' ?>"><?= count($stateEntries) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="board-column-body">
                                            <?php foreach ($stateEntries as $id): ?>
                                                <form method="post">
                                                    <button type="submit" name="select_order" value="<?= htmlspecialchars($id) ?>"
                                                        class="board-card d-block w-100 text-start border-0 <?= $id === $selectedOrderId ? 'selected' : '' ?>">
                                                        <strong><?= htmlspecialchars($id) ?></strong>
                                                    </button>
                                                </form>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="order-list">
                                <div class="list-group list-group-flush">
                                    <?php foreach ($allOrders as $id => $state): ?>
                                        <div class="list-group-item d-flex justify-content-between align-items-center <?= $id === $selectedOrderId ? 'active' : '' ?>">
                                            <form method="post" class="flex-grow-1">
                                                <button type="submit" name="select_order" value="<?= htmlspecialchars($id) ?>"
                                                    class="btn btn-link text-decoration-none p-0 <?= $id === $selectedOrderId ? 'text-white' : '' ?>">
                                                    <strong><?= htmlspecialchars($id) ?></strong>
                                                </button>
                                            </form>
                                            <span class="badge <?= getStateBadgeClass($state, $terminalStates) ?> me-2">
                                                <?= htmlspecialchars(str_replace('_', ' ', $state)) ?>
                                            </span>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="delete_id" value="<?= htmlspecialchars($id) ?>">
                                                <button type="submit" name="delete_order" value="1"
                                                    class="btn btn-sm <?= $id === $selectedOrderId ? 'btn-outline-light' : 'btn-outline-danger' ?>"
                                                    onclick="return confirm('Delete <?= htmlspecialchars($id) ?>?')">×</button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">State Machine Diagram</h5>
                    </div>
                    <div class="card-body">
                        <pre class="mermaid">
<?= htmlspecialchars($mermaid) ?>
                        </pre>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <?php if ($order && $currentState): ?>
                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <?= htmlspecialchars($selectedOrderId) ?>
                            <span class="badge <?= getStateBadgeClass($currentState, $terminalStates) ?> ms-2">
                                <?= htmlspecialchars(str_replace('_', ' ', $currentState)) ?>
                            </span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($availableEvents)): ?>
                            <p class="text-muted mb-0">Terminal state reached — no transitions available.</p>
                        <?php else: ?>
                            <?php foreach ($availableEvents as $event): ?>
                                <form method="post" class="mb-2">
                                    <?php if ($event === 'payment_success'): ?>
                                        <div class="input-group mb-1">
                                            <input type="text" name="payment_method" class="form-control" placeholder="Payment method (e.g. credit_card)" required>
                                            <button type="submit" name="event" value="<?= htmlspecialchars($event) ?>" class="btn btn-primary">
                                                <?= htmlspecialchars(str_replace('_', ' ', $event)) ?>
                                            </button>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="stock_available" value="1" id="stockAvailable" checked>
                                            <label class="form-check-label small" for="stockAvailable">Stock available (triggers auto-process)</label>
                                        </div>
                                    <?php else: ?>
                                        <button type="submit" name="event" value="<?= htmlspecialchars($event) ?>" class="btn btn-primary w-100">
                                            <?= htmlspecialchars(str_replace('_', ' ', $event)) ?>
                                        </button>
                                    <?php endif; ?>
                                </form>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($pendingTimeouts)): ?>
                <div class="card mb-3 border-warning">
                    <div class="card-header bg-warning bg-opacity-10">
                        <h5 class="mb-0">Pending Timeout</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($pendingTimeouts as $timeout): ?>
                        <p class="mb-2">
                            <strong><?= htmlspecialchars($timeout['event']) ?></strong>
                            on state <span class="badge bg-secondary"><?= htmlspecialchars($timeout['state']) ?></span>
                            <br><small class="text-muted">Due: <?= htmlspecialchars((new DateTimeImmutable($timeout['due_at']))->format('Y-m-d H:i:s')) ?></small>
                        </p>
                        <?php endforeach; ?>
                        <form method="post">
                            <div class="input-group">
                                <input type="number" name="minutes" class="form-control" value="31" min="1" max="1440">
                                <span class="input-group-text">min</span>
                                <button type="submit" name="simulate_timeout" value="1" class="btn btn-warning">Simulate time</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="mb-0">Transition History</h5>
                    </div>
                    <div class="card-body history-log">
                        <?php if (empty($history)): ?>
                            <p class="text-muted mb-0">No transitions yet.</p>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach (array_reverse($history) as $entry): ?>
                                    <li class="list-group-item px-0 py-1">
                                        <small class="text-muted"><?= htmlspecialchars($entry['created_at']) ?></small>
                                        <span class="badge bg-secondary"><?= htmlspecialchars($entry['from_state']) ?></span>
                                        &rarr; <strong><?= htmlspecialchars($entry['event']) ?></strong> &rarr;
                                        <span class="badge <?= getStateBadgeClass($entry['to_state'], $terminalStates) ?>">
                                            <?= htmlspecialchars($entry['to_state']) ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>

                <?php else: ?>
                <div class="card mb-3">
                    <div class="card-body text-center text-muted py-5">
                        <p class="fs-5 mb-1">No entry selected</p>
                        <p>Select an entry from the list or create a new one.</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($order && $currentState): ?>
        <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">PSR-14 Event Log</h5>
                    </div>
                    <div class="card-body event-log">
                        <?php if (empty($eventLog)): ?>
                            <p class="text-muted mb-0">No events dispatched yet (session-scoped).</p>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach (array_reverse($eventLog) as $entry): ?>
                                    <li class="list-group-item px-0 py-1">
                                        <small class="text-muted"><?= $entry['timestamp'] ?></small>
                                        <?php if ($entry['type'] === 'after_transition'): ?>
                                            <span class="badge bg-success">AfterTransition</span>
                                            <?= htmlspecialchars($entry['source']) ?> &rarr;
                                            <strong><?= htmlspecialchars($entry['event']) ?></strong> &rarr;
                                            <?= htmlspecialchars($entry['target'] ?? '') ?>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">TransitionBlocked</span>
                                            <?= htmlspecialchars($entry['source']) ?> &rarr;
                                            <strong><?= htmlspecialchars($entry['event']) ?></strong>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Notifications (simulated)</h5>
                    </div>
                    <div class="card-body event-log">
                        <?php if (empty($notifications)): ?>
                            <p class="text-muted mb-0">No notifications sent yet (session-scoped).</p>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach (array_reverse($notifications) as $notif): ?>
                                    <li class="list-group-item px-0 py-1">
                                        <small class="text-muted"><?= $notif['timestamp'] ?></small>
                                        <span class="badge bg-info text-dark"><?= htmlspecialchars($notif['target']) ?></span>
                                        <?= htmlspecialchars($notif['message']) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/mermaid@10.9.0/dist/mermaid.min.js"></script>
    <script>
        mermaid.initialize({ startOnLoad: true, theme: 'default' });
    </script>
</body>
</html>
