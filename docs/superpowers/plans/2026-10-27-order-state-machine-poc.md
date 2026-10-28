# Order State Machine POC — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a POC that evaluates the `tlab/state-machine` PHP library for order processing, with three interfaces (CLI, tests, web UI) driven by a single JSON config file.

**Architecture:** A single JSON definition (`config/order-state-machine.json`) describes all states, transitions, events, conditions, and commands. The PHP layer is config-agnostic — it reads whatever the JSON defines and renders dynamically. Three entry points (CLI, web, tests) share a bootstrap that loads the JSON and builds the `StateMachineRunner`. Guards and commands are generic, reusable classes wired via FQCNs in the JSON config.

**Tech Stack:** PHP 8.2+, `tlab/state-machine`, PHPUnit 11, Bootstrap 5 (CDN), Mermaid.js (CDN for web UI, `jbzoo/mermaid-php` for CLI diagrams).

**Spec:** `docs/superpowers/specs/2026-10-27-order-state-machine-design.md` (this plan is self-contained; no separate spec was written — the brainstorming conversation serves as the spec).

## Global Constraints

- PHP 8.2+ required by the library
- No framework — plain PHP with Composer autoloading only
- Single JSON config is the source of truth — modifying states/transitions must not require PHP changes
- Guards/conditions implement `Tlab\StateMachine\Conditions\ConditionInterface` (method: `check(array $data): bool`)
- Commands implement `Tlab\StateMachine\Commands\CommandInterface` (method: `run(array $data): void`)
- In-memory state storage only (no database)
- Bootstrap 5.3.x via CDN for web UI
- Mermaid.js 10.x via CDN for web UI diagrams

## Review Focus

1. **Invalid event name in CLI/web input** — submitting a non-existent event should show a clear error, not crash or silently do nothing. Tested in Task 4 step for CLI and Task 5 for web.
2. **JSON config with unreachable states** — a state with no inbound transitions (other than initial) should still render in the diagram and not break the runner. Tested in Task 3.
3. **Concurrent transitions from same state+event with no matching condition** — when all guards fail, `apply()` returns `Blocked` status; the UI should show why. Tested in Task 3.
4. **Empty `$data` array passed to conditions** — guards that inspect `$data` keys must handle missing keys gracefully. Tested in Task 2.
5. **Modified JSON config with removed state that was the current state** — the runner should handle this at the edges; documented as known limitation in README.

## File Structure

```
poc-state-machine/
├── composer.json
├── config/
│   └── order-state-machine.json          # Single source of truth
├── src/
│   ├── Bootstrap.php                      # Builds StateMachineRunner from JSON config
│   ├── Conditions/
│   │   ├── OrderNotShippedCondition.php   # Guard: blocks if state is shipped+
│   │   └── HasPaymentDataCondition.php    # Guard: checks $data['payment_method'] exists
│   └── Commands/
│       └── LogTransitionCommand.php       # Logs transition to stdout/array
├── bin/
│   └── cli.php                            # Interactive CLI demo
├── public/
│   └── index.php                          # Web UI entry point
├── tests/
│   ├── Unit/
│   │   ├── ConditionsTest.php             # Guard unit tests
│   │   └── CommandsTest.php               # Command unit tests
│   └── Integration/
│       ├── HappyPathTest.php              # Full happy path flow
│       ├── CancellationTest.php           # Cancellation paths
│       ├── PaymentFailureTest.php         # Payment retry/failure paths
│       ├── ReturnFlowTest.php             # Return/refund paths
│       └── EdgeCasesTest.php              # Invalid events, blocked transitions, boundary cases
└── phpunit.xml
```

---

### Task 1: Project Scaffolding and JSON Config

**Files:**
- Create: `composer.json`
- Create: `config/order-state-machine.json`
- Create: `phpunit.xml`

**Interfaces:**
- Consumes: nothing
- Produces: `config/order-state-machine.json` (consumed by all other tasks), Composer autoloading

- [ ] **Step 1: Create `composer.json`**

```json
{
    "name": "poc/order-state-machine",
    "description": "POC evaluating tlab/state-machine for order processing",
    "type": "project",
    "require": {
        "php": "^8.2",
        "tlab/state-machine": "^1.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.3",
        "jbzoo/mermaid-php": "^7.2"
    },
    "autoload": {
        "psr-4": {
            "Tlab\\StatemachinePlayground\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tlab\\StatemachinePlayground\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Run `composer install`**

Expected: dependencies installed, `vendor/` created, autoloader generated.

- [ ] **Step 3: Create `config/order-state-machine.json`**

The JSON defines 11 states and 14 transitions following the library's schema. Key design decisions:

**States:** `new`, `pending_payment`, `payment_failed`, `confirmed`, `processing`, `shipped`, `delivered`, `lost`, `return_requested`, `returned`, `cancelled`, `refunded`. State `new` has `"isCurrent": true`.

**Transitions with conditions:**
- `cancel` from `confirmed` → `cancelled`: condition `Tlab\StatemachinePlayground\Conditions\OrderNotShippedCondition`
- `payment_success` from `pending_payment` → `confirmed`: condition `Tlab\StatemachinePlayground\Conditions\HasPaymentDataCondition`

**Transitions with commands:**
- `ship` from `processing` → `shipped`: command `Tlab\StatemachinePlayground\Commands\LogTransitionCommand`
- `deliver` from `shipped` → `delivered`: command `Tlab\StatemachinePlayground\Commands\LogTransitionCommand`
- `refund` from `returned` → `refunded`: command `Tlab\StatemachinePlayground\Commands\LogTransitionCommand`
- `refund` from `cancelled` → `refunded`: command `Tlab\StatemachinePlayground\Commands\LogTransitionCommand`

**Events:** all events are `"manual": true` (UI-driven). Events: `place_order`, `payment_success`, `payment_failure`, `retry_payment`, `cancel`, `start_processing`, `ship`, `deliver`, `lost_in_transit`, `request_return`, `approve_return`, `reject_return`, `refund`.

Full JSON structure:

```json
{
    "name": "order",
    "states": [
        {"name": "new", "isCurrent": true},
        {"name": "pending_payment"},
        {"name": "payment_failed"},
        {"name": "confirmed"},
        {"name": "processing"},
        {"name": "shipped"},
        {"name": "delivered"},
        {"name": "lost"},
        {"name": "return_requested"},
        {"name": "returned"},
        {"name": "cancelled"},
        {"name": "refunded"}
    ],
    "transitions": [
        {"source": "new", "target": "pending_payment", "event": "place_order"},
        {"source": "pending_payment", "target": "confirmed", "event": "payment_success", "condition": "Tlab\\StatemachinePlayground\\Conditions\\HasPaymentDataCondition"},
        {"source": "pending_payment", "target": "payment_failed", "event": "payment_failure"},
        {"source": "payment_failed", "target": "pending_payment", "event": "retry_payment"},
        {"source": "payment_failed", "target": "cancelled", "event": "cancel"},
        {"source": "confirmed", "target": "processing", "event": "start_processing"},
        {"source": "confirmed", "target": "cancelled", "event": "cancel", "condition": "Tlab\\StatemachinePlayground\\Conditions\\OrderNotShippedCondition"},
        {"source": "processing", "target": "shipped", "event": "ship", "command": "Tlab\\StatemachinePlayground\\Commands\\LogTransitionCommand"},
        {"source": "shipped", "target": "delivered", "event": "deliver", "command": "Tlab\\StatemachinePlayground\\Commands\\LogTransitionCommand"},
        {"source": "shipped", "target": "lost", "event": "lost_in_transit"},
        {"source": "delivered", "target": "return_requested", "event": "request_return"},
        {"source": "return_requested", "target": "returned", "event": "approve_return"},
        {"source": "return_requested", "target": "delivered", "event": "reject_return"},
        {"source": "returned", "target": "refunded", "event": "refund", "command": "Tlab\\StatemachinePlayground\\Commands\\LogTransitionCommand"},
        {"source": "cancelled", "target": "refunded", "event": "refund", "command": "Tlab\\StatemachinePlayground\\Commands\\LogTransitionCommand"}
    ],
    "events": [
        {"name": "place_order", "manual": true},
        {"name": "payment_success", "manual": true},
        {"name": "payment_failure", "manual": true},
        {"name": "retry_payment", "manual": true},
        {"name": "cancel", "manual": true},
        {"name": "start_processing", "manual": true},
        {"name": "ship", "manual": true},
        {"name": "deliver", "manual": true},
        {"name": "lost_in_transit", "manual": true},
        {"name": "request_return", "manual": true},
        {"name": "approve_return", "manual": true},
        {"name": "reject_return", "manual": true},
        {"name": "refund", "manual": true}
    ]
}
```

- [ ] **Step 4: Create `phpunit.xml`**

Bootstrap with `vendor/autoload.php`. Test suites: `Unit` pointing to `tests/Unit/`, `Integration` pointing to `tests/Integration/`.

- [ ] **Step 5: Verify JSON is valid by loading it**

```bash
php -r "
require 'vendor/autoload.php';
\$reader = new Tlab\StateMachine\Reader\DefinitionReader();
\$sm = \$reader->readFile('config/order-state-machine.json');
echo 'Machine: ' . \$sm->getName() . PHP_EOL;
echo 'States: ' . count(\$sm->getStates()) . PHP_EOL;
echo 'Transitions: ' . count(\$sm->getTransitions()) . PHP_EOL;
"
```

Expected: `Machine: order`, `States: 12`, `Transitions: 15`. (This will fail on conditions not yet existing — that's expected; the JSON schema validation should still pass. If it fails, temporarily remove the condition FQCNs, verify, then restore them.)

- [ ] **Step 6: Commit**

```bash
git init
git add composer.json composer.lock config/order-state-machine.json phpunit.xml
git commit -m "feat: project scaffolding with JSON state machine config"
```

---

### Task 2: Conditions and Commands

**Files:**
- Create: `src/Conditions/OrderNotShippedCondition.php`
- Create: `src/Conditions/HasPaymentDataCondition.php`
- Create: `src/Commands/LogTransitionCommand.php`
- Create: `tests/Unit/ConditionsTest.php`
- Create: `tests/Unit/CommandsTest.php`

**Interfaces:**
- Consumes: `Tlab\StateMachine\Conditions\ConditionInterface`, `Tlab\StateMachine\Commands\CommandInterface`
- Produces:
  - `Tlab\StatemachinePlayground\Conditions\OrderNotShippedCondition::check(array $data): bool` — returns `true` when `$data['current_state']` is not in `['shipped', 'delivered', 'lost', 'return_requested', 'returned', 'refunded']`
  - `Tlab\StatemachinePlayground\Conditions\HasPaymentDataCondition::check(array $data): bool` — returns `true` when `$data['payment_method']` is set and non-empty
  - `Tlab\StatemachinePlayground\Commands\LogTransitionCommand::run(array $data): void` — appends to an internal array; `getLog(): array` returns collected entries

- [ ] **Step 1: Write failing tests for `OrderNotShippedCondition`**

```php
// tests/Unit/ConditionsTest.php
public function testOrderNotShippedReturnsTrueForPreShipStates(): void
{
    $condition = new OrderNotShippedCondition();
    $this->assertTrue($condition->check(['current_state' => 'confirmed']));
    $this->assertTrue($condition->check(['current_state' => 'processing']));
}

public function testOrderNotShippedReturnsFalseForPostShipStates(): void
{
    $condition = new OrderNotShippedCondition();
    $this->assertFalse($condition->check(['current_state' => 'shipped']));
    $this->assertFalse($condition->check(['current_state' => 'delivered']));
}

public function testOrderNotShippedReturnsTrueWhenNoStateInData(): void
{
    $condition = new OrderNotShippedCondition();
    $this->assertTrue($condition->check([]));
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/ConditionsTest.php -v`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `OrderNotShippedCondition`**

Implements `ConditionInterface`. The `check()` method reads `$data['current_state']` and returns `true` if it's absent or not in the post-ship states list.

- [ ] **Step 4: Write failing tests for `HasPaymentDataCondition`**

```php
public function testHasPaymentDataReturnsTrueWithPaymentMethod(): void
{
    $condition = new HasPaymentDataCondition();
    $this->assertTrue($condition->check(['payment_method' => 'credit_card']));
}

public function testHasPaymentDataReturnsFalseWithoutPaymentMethod(): void
{
    $condition = new HasPaymentDataCondition();
    $this->assertFalse($condition->check([]));
    $this->assertFalse($condition->check(['payment_method' => '']));
}
```

- [ ] **Step 5: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/ConditionsTest.php -v`
Expected: FAIL — class not found.

- [ ] **Step 6: Implement `HasPaymentDataCondition`**

Implements `ConditionInterface`. Returns `true` when `$data['payment_method']` is set and non-empty.

- [ ] **Step 7: Run all condition tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/ConditionsTest.php -v`
Expected: all PASS.

- [ ] **Step 8: Write failing tests for `LogTransitionCommand`**

```php
// tests/Unit/CommandsTest.php
public function testLogTransitionCommandRecordsData(): void
{
    $command = new LogTransitionCommand();
    $command->run(['from' => 'new', 'to' => 'pending_payment', 'event' => 'place_order']);
    $log = $command->getLog();
    $this->assertCount(1, $log);
    $this->assertSame('place_order', $log[0]['event']);
}
```

- [ ] **Step 9: Implement `LogTransitionCommand`**

Implements `CommandInterface`. Stores each `$data` array in an internal `array $log`. Provides `getLog(): array` for retrieval.

- [ ] **Step 10: Run all unit tests**

Run: `vendor/bin/phpunit tests/Unit/ -v`
Expected: all PASS.

- [ ] **Step 11: Commit**

```bash
git add src/Conditions/ src/Commands/ tests/Unit/
git commit -m "feat: add order conditions and log command with unit tests"
```

---

### Task 3: Bootstrap and Integration Tests

**Files:**
- Create: `src/Bootstrap.php`
- Create: `tests/Integration/HappyPathTest.php`
- Create: `tests/Integration/CancellationTest.php`
- Create: `tests/Integration/PaymentFailureTest.php`
- Create: `tests/Integration/ReturnFlowTest.php`
- Create: `tests/Integration/EdgeCasesTest.php`

**Interfaces:**
- Consumes: `Tlab\StateMachine\Reader\DefinitionReader::readFile(string $path): StateMachine`, `Tlab\StateMachine\StateMachineRunner` constructor, conditions from Task 2
- Produces:
  - `Tlab\StatemachinePlayground\Bootstrap::createRunner(string $configPath = null): StateMachineRunner` — loads JSON, wires up the runner with default resolver
  - `Tlab\StatemachinePlayground\Bootstrap::getConfigPath(): string` — returns default config path

- [ ] **Step 1: Write failing test for the happy path**

```php
// tests/Integration/HappyPathTest.php
public function testFullHappyPath(): void
{
    $runner = Bootstrap::createRunner();
    $state = 'new';

    $result = $runner->apply($state, 'place_order');
    $this->assertTrue($result->hasMoved());
    $state = $result->getState();
    $this->assertSame('pending_payment', $state);

    $result = $runner->apply($state, 'payment_success', ['payment_method' => 'credit_card']);
    $this->assertTrue($result->hasMoved());
    $state = $result->getState();
    $this->assertSame('confirmed', $state);

    // continue through: start_processing → processing → ship → shipped → deliver → delivered
    $steps = [
        ['start_processing', [], 'processing'],
        ['ship', [], 'shipped'],
        ['deliver', [], 'delivered'],
    ];
    foreach ($steps as [$event, $data, $expected]) {
        $result = $runner->apply($state, $event, $data);
        $this->assertTrue($result->hasMoved());
        $state = $result->getState();
        $this->assertSame($expected, $state);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/HappyPathTest.php -v`
Expected: FAIL — Bootstrap class not found.

- [ ] **Step 3: Implement `Bootstrap::createRunner()`**

Uses `DefinitionReader::readFile()` to load the JSON config. Accepts an optional `$configPath` parameter, defaults to `__DIR__ . '/../config/order-state-machine.json'`. Returns a configured `StateMachineRunner`.

- [ ] **Step 4: Run happy path test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/HappyPathTest.php -v`
Expected: PASS.

- [ ] **Step 5: Write and verify cancellation tests**

```php
// tests/Integration/CancellationTest.php
public function testCancelFromConfirmed(): void
{
    $runner = Bootstrap::createRunner();
    // Walk to confirmed state
    $state = 'confirmed';
    $result = $runner->apply($state, 'cancel', ['current_state' => 'confirmed']);
    $this->assertTrue($result->hasMoved());
    $this->assertSame('cancelled', $result->getState());
}

public function testCancelFromPaymentFailed(): void
{
    $runner = Bootstrap::createRunner();
    $result = $runner->apply('payment_failed', 'cancel');
    $this->assertTrue($result->hasMoved());
    $this->assertSame('cancelled', $result->getState());
}

public function testRefundAfterCancellation(): void
{
    $runner = Bootstrap::createRunner();
    $result = $runner->apply('cancelled', 'refund');
    $this->assertTrue($result->hasMoved());
    $this->assertSame('refunded', $result->getState());
}
```

Run: `vendor/bin/phpunit tests/Integration/CancellationTest.php -v`
Expected: all PASS.

- [ ] **Step 6: Write and verify payment failure tests**

```php
// tests/Integration/PaymentFailureTest.php
public function testPaymentFailureAndRetry(): void
{
    $runner = Bootstrap::createRunner();
    $result = $runner->apply('pending_payment', 'payment_failure');
    $this->assertTrue($result->hasMoved());
    $this->assertSame('payment_failed', $result->getState());

    $result = $runner->apply('payment_failed', 'retry_payment');
    $this->assertTrue($result->hasMoved());
    $this->assertSame('pending_payment', $result->getState());
}

public function testPaymentSuccessWithoutPaymentDataIsBlocked(): void
{
    $runner = Bootstrap::createRunner();
    $result = $runner->apply('pending_payment', 'payment_success', []);
    $this->assertFalse($result->hasMoved());
}
```

Run: `vendor/bin/phpunit tests/Integration/PaymentFailureTest.php -v`
Expected: all PASS.

- [ ] **Step 7: Write and verify return flow tests**

```php
// tests/Integration/ReturnFlowTest.php
public function testReturnApprovedAndRefunded(): void
{
    $runner = Bootstrap::createRunner();
    $result = $runner->apply('delivered', 'request_return');
    $this->assertTrue($result->hasMoved());
    $this->assertSame('return_requested', $result->getState());

    $result = $runner->apply('return_requested', 'approve_return');
    $this->assertTrue($result->hasMoved());
    $this->assertSame('returned', $result->getState());

    $result = $runner->apply('returned', 'refund');
    $this->assertTrue($result->hasMoved());
    $this->assertSame('refunded', $result->getState());
}

public function testReturnRejected(): void
{
    $runner = Bootstrap::createRunner();
    $result = $runner->apply('return_requested', 'reject_return');
    $this->assertTrue($result->hasMoved());
    $this->assertSame('delivered', $result->getState());
}
```

Run: `vendor/bin/phpunit tests/Integration/ReturnFlowTest.php -v`
Expected: all PASS.

- [ ] **Step 8: Write and verify edge case tests**

```php
// tests/Integration/EdgeCasesTest.php
public function testInvalidEventReturnsNoTransition(): void
{
    $runner = Bootstrap::createRunner();
    $result = $runner->apply('new', 'nonexistent_event');
    $this->assertSame(TransitionStatus::NoTransition, $result->getStatus());
}

public function testCannotShipFromNew(): void
{
    $runner = Bootstrap::createRunner();
    $result = $runner->apply('new', 'ship');
    $this->assertSame(TransitionStatus::NoTransition, $result->getStatus());
}

public function testAvailableEventsFromNew(): void
{
    $runner = Bootstrap::createRunner();
    $events = $runner->availableEvents('new');
    $this->assertSame(['place_order'], $events);
}

public function testAvailableEventsFromShipped(): void
{
    $runner = Bootstrap::createRunner();
    $events = $runner->availableEvents('shipped');
    $this->assertContains('deliver', $events);
    $this->assertContains('lost_in_transit', $events);
}

public function testLostInTransit(): void
{
    $runner = Bootstrap::createRunner();
    $result = $runner->apply('shipped', 'lost_in_transit');
    $this->assertTrue($result->hasMoved());
    $this->assertSame('lost', $result->getState());
}

public function testRefundedIsTerminal(): void
{
    $runner = Bootstrap::createRunner();
    $events = $runner->availableEvents('refunded');
    $this->assertEmpty($events);
}

public function testLostIsTerminal(): void
{
    $runner = Bootstrap::createRunner();
    $events = $runner->availableEvents('lost');
    $this->assertEmpty($events);
}
```

Run: `vendor/bin/phpunit tests/Integration/EdgeCasesTest.php -v`
Expected: all PASS.

- [ ] **Step 9: Run entire test suite**

Run: `vendor/bin/phpunit -v`
Expected: all tests PASS.

- [ ] **Step 10: Commit**

```bash
git add src/Bootstrap.php tests/Integration/ tests/Unit/
git commit -m "feat: add bootstrap and full integration test suite"
```

---

### Task 4: Interactive CLI Demo

**Files:**
- Create: `bin/cli.php`

**Interfaces:**
- Consumes: `Tlab\StatemachinePlayground\Bootstrap::createRunner()`, `StateMachineRunner::apply()`, `StateMachineRunner::availableEvents()`, `StateMachineRunner::generateMarkdownDiagram()`
- Produces: `bin/cli.php` — executable CLI script

- [ ] **Step 1: Implement `bin/cli.php`**

The script runs an interactive loop:

1. Display current state (highlighted)
2. List available events (numbered menu)
3. Accept user input (number to select event, `diagram` to show Mermaid diagram, `quit` to exit)
4. For `payment_success` event, prompt for `payment_method` to populate `$data`
5. For `cancel` event from `confirmed`, populate `$data['current_state']` automatically
6. Call `$runner->apply($state, $event, $data)` and display the result (`Moved`, `Blocked`, `NoTransition`)
7. If terminal state (no available events), show "Order reached terminal state" and exit

Use `readline()` for input. Use ANSI color codes for state highlighting (green for current state, red for blocked, yellow for terminal).

The script accepts an optional `--config` argument to point to a different JSON file, defaulting to the project's `config/order-state-machine.json`.

- [ ] **Step 2: Manual test — run the happy path**

```bash
php bin/cli.php
```

Walk through: place_order → payment_success (enter "credit_card") → start_processing → ship → deliver. Verify each state transition displays correctly.

- [ ] **Step 3: Manual test — cancellation flow**

Start fresh, walk to `confirmed`, then choose `cancel`. Verify it transitions to `cancelled`.

- [ ] **Step 4: Manual test — invalid event handling**

Verify that selecting a non-listed option shows an error message and re-prompts.

- [ ] **Step 5: Manual test — diagram output**

Type `diagram` at any prompt. Verify Mermaid markdown is printed.

- [ ] **Step 6: Commit**

```bash
git add bin/cli.php
git commit -m "feat: add interactive CLI demo"
```

---

### Task 5: Web UI

**Files:**
- Create: `public/index.php`

**Interfaces:**
- Consumes: `Tlab\StatemachinePlayground\Bootstrap::createRunner()`, `StateMachineRunner::apply()`, `StateMachineRunner::availableEvents()`, `StateMachine::toJson()`, `StateMachine::getStates()`, `StateMachine::getTransitions()`
- Produces: `public/index.php` — single-file web UI

- [ ] **Step 1: Implement `public/index.php`**

Single PHP file that handles both GET (display) and POST (transition). The page layout:

**Header:** "Order State Machine POC" title.

**Left column (8 cols):** Mermaid diagram of the full state machine. The current state node is styled differently (green fill). Generate the Mermaid definition dynamically from `$stateMachine->getStates()` and `$stateMachine->getTransitions()` — iterate states to create nodes, iterate transitions to create edges with event labels. Apply a `classDef current` style to the active state node.

**Right column (4 cols):** 
- Current state displayed as a Bootstrap badge (color-coded: green for happy path states, red for failure states, blue for terminal)
- Transition history log (stored in `$_SESSION`)
- Available events as Bootstrap buttons (each is a form POST with `event` field)
- For `payment_success`, include a text input for `payment_method`
- Result message after each transition (success/blocked/no-transition) as a Bootstrap alert

**Technical details:**
- `session_start()` at top to store `$currentState` and `$history` in `$_SESSION`
- POST handler: read `$_POST['event']`, build `$data` array (include `payment_method` if present, include `current_state` for condition checks), call `$runner->apply()`, update session
- Reset button to clear session and start over
- Bootstrap 5.3.3 via CDN (`<link>` and `<script>`)
- Mermaid.js 10.9.0 via CDN
- Responsive layout using Bootstrap grid

- [ ] **Step 2: Start PHP built-in server**

```bash
php -S localhost:8080 -t public/
```

- [ ] **Step 3: Manual test — happy path in browser**

Open `http://localhost:8080`. Walk through place_order → payment_success → start_processing → ship → deliver. Verify:
- Mermaid diagram renders and highlights current state
- Transition buttons update dynamically
- History log shows each step

- [ ] **Step 4: Manual test — payment failure and retry**

From `pending_payment`, click `payment_failure`. Verify `payment_failed` state. Click `retry_payment`. Verify back to `pending_payment`.

- [ ] **Step 5: Manual test — cancellation**

Walk to `confirmed`, click `cancel`. Verify `cancelled`. Click `refund`. Verify `refunded` and no more buttons.

- [ ] **Step 6: Manual test — return flow**

Walk to `delivered`, click `request_return` → `approve_return` → `refund`. Verify full flow.

- [ ] **Step 7: Manual test — reset**

Click reset. Verify state goes back to `new` and history is cleared.

- [ ] **Step 8: Manual test — modify JSON config**

Add a new state (e.g. `"on_hold"`) and a transition (`confirmed` → `on_hold` via `hold` event) to the JSON file. Reload the web page. Verify the new state appears in the diagram and the `hold` button appears when in `confirmed` state. Remove the change after verifying.

- [ ] **Step 9: Commit**

```bash
git add public/index.php
git commit -m "feat: add web UI with Bootstrap 5 and Mermaid diagram"
```

---

### Task 6: Final Polish and README

**Files:**
- Create: `.gitignore`
- Create: `README.md`

**Interfaces:**
- Consumes: everything from Tasks 1–5
- Produces: project documentation

- [ ] **Step 1: Create `.gitignore`**

```
/vendor/
.phpunit.result.cache
```

- [ ] **Step 2: Write `README.md`**

Sections:
- **What this is:** one paragraph — POC evaluating `tlab/state-machine` for order processing
- **Requirements:** PHP 8.2+, Composer
- **Setup:** `composer install`
- **Run tests:** `vendor/bin/phpunit`
- **CLI demo:** `php bin/cli.php` with brief usage notes
- **Web UI:** `php -S localhost:8080 -t public/` then open browser
- **Modify the state machine:** explain that `config/order-state-machine.json` is the single source of truth, show example of adding a state/transition
- **Library evaluation notes:** bullet list of findings (what worked well, what's missing, any gotchas discovered during the POC)

- [ ] **Step 3: Run full test suite one final time**

Run: `vendor/bin/phpunit -v`
Expected: all PASS.

- [ ] **Step 4: Commit**

```bash
git add .gitignore README.md
git commit -m "docs: add README and gitignore"
```
