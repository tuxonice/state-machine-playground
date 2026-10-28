# State Machine POC

A proof-of-concept evaluating the [tuxonice/state-machine](https://github.com/tuxonice/state-machine) PHP library for stateful workflows. Includes two configs: an order processing pipeline and a real estate lead management workflow.

## Requirements

- Docker and Docker Compose, **or**
- PHP 8.2+ and Composer

## Docker (recommended)

Build and start the web UI:

```bash
docker compose up --build
```

Then open http://localhost:8080 in your browser.

Run tests:

```bash
docker compose run --rm web vendor/bin/phpunit
```

Run the CLI demo:

```bash
docker compose run --rm web php bin/cli.php                                    # order machine
docker compose run --rm web php bin/cli.php --config config/lead-state-machine.json  # lead machine
```

All available commands via `make help`. Source files are mounted as volumes, so changes to `config/`, `src/`, `public/`, `bin/`, and `tests/` are reflected immediately without rebuilding.

## Local Setup (without Docker)

```bash
composer install
```

## Run Tests

```bash
vendor/bin/phpunit
```

## CLI Demo

Interactive terminal demo that lets you walk through order transitions step by step:

```bash
php bin/cli.php
```

Use a custom config:

```bash
php bin/cli.php --config path/to/your-config.json
```

Features:
- Order entity via `StatefulInterface` — state lives on the entity
- PSR-14 event dispatching with transition log and notification simulation
- Numbered menu of available events
- Type `d` for a Mermaid diagram of the state machine
- Type `l` to view the PSR-14 event log and notifications
- Type `t` to simulate time passing and trigger timeouts
- Pending timeouts displayed with due time
- Type `q` to quit

## Web UI

Start the PHP built-in server:

```bash
php -S localhost:8080 -t public/
```

Then open http://localhost:8080 in your browser.

Features:
- **Config-agnostic** — auto-discovers all `*-state-machine.json` files in `config/`; title, diagram, states, and terminal states are derived from whichever config is active
- **Multi-machine switcher** — buttons at the top switch between configs (e.g. "Real Estate Lead" | "Order"); each machine has its own scoped entry list
- **Multi-entry management** — create, select, and delete entries; each persisted independently with machine-prefixed IDs (e.g. `ORD-1234`, `REA-5678`)
- **SQLite persistence** via custom `StateStorageInterface` — entry states, transition history, and scheduled timeouts survive server restarts
- **Persistent timeout scheduler** — `SqliteTimeoutScheduler` stores scheduled timeouts in SQLite; pending timeouts display per entry with a "Simulate time" button
- Visual Mermaid diagram with current state highlighted
- Click-to-transition buttons
- Persistent transition history per entry (stored in SQLite)
- PSR-14 event log panel (shows `AfterTransition` and `TransitionBlocked` events, session-scoped)
- Simulated notifications panel (triggered on key transitions)
- Payment method input and stock availability checkbox for payment_success event (order config)
- onEnter auto-transition chain display (e.g. payment_success → auto_process → processing)

## State Machine Configs

Each JSON config in `config/` is a self-contained state machine definition. The web UI discovers them automatically; the CLI accepts any via `--config`.

| Config | States | Transitions | Key features |
|--------|--------|-------------|-------------|
| `order-state-machine.json` | 12 | 17 | payment guards, stock checks, onEnter auto-transitions, 30-min auto_cancel timeout |
| `lead-state-machine.json` | 13 | 30 | nurturing loop, rejection recovery, 48-hour auto_expire timeout |

To add a new machine, drop a `*-state-machine.json` file into `config/`. The web UI will show a new switcher button, and `--config` works from the CLI.

### Adding a state and transition

1. Add the new state to the `states` array:
   ```json
   {"name": "on_hold"}
   ```

2. Add a transition to/from it in the `transitions` array:
   ```json
   {"source": "confirmed", "target": "on_hold", "event": "hold"}
   ```

3. Add the event in the `events` array:
   ```json
   {"name": "hold", "manual": true}
   ```

No PHP changes needed. The CLI and web UI will show the new state and transition automatically.

### Adding a guard condition

1. Create a class implementing `Tlab\StateMachine\Conditions\ConditionInterface`
2. Reference it in the transition's `"condition"` field using the FQCN

### Adding a command (side effect)

1. Create a class implementing `Tlab\StateMachine\Commands\CommandInterface`
2. Reference it in the transition's `"command"` field using the FQCN

## Library Evaluation Notes

**What works well:**
- Clean JSON-based configuration — easy to understand and modify
- `apply()` returns a `TransitionResult` with clear status semantics (`Moved`, `Blocked`, `NoTransition`)
- Guard conditions via `ConditionInterface` are simple and composable
- Built-in Mermaid diagram generation is useful for visualization
- `availableEvents()` makes dynamic UIs straightforward
- Good exception hierarchy (`UnknownEventException` for invalid events)
- No framework dependency — works anywhere
- `StatefulInterface` lets entities own their state — `applyTo()` reads/writes state on the object
- PSR-14 event dispatching (`BeforeTransition`, `AfterTransition`, `TransitionBlocked`) enables audit logging, notifications, and side effects without coupling to the state machine
- Timeout events with `TimeoutScheduler` — define `"timeout": "30 minutes"` in JSON, the runner auto-schedules/cancels on state changes, and `processTimeouts()` fires due events
- onEnter auto-transitions — define `"onEnter": true` on an event and the runner chains it automatically when entering a state, with conditions gating whether it fires; each step dispatches its own PSR-14 events
- `StateStorageInterface` is clean and minimal (`read(object): ?string`, `write(object, string): void`) — implementing custom persistence (e.g. SQLite, Redis, database) is straightforward. The runner delegates all state I/O through it, so swapping storage requires zero changes to business logic
- The runner constructor accepts `?StateStorageInterface $storage` — defaults to `StatefulStateStorage` (delegates to the entity's `StatefulInterface`), so in-memory and persistent storage coexist without config changes
- Custom storage can layer on top of `StatefulInterface` — our `SqliteStateStorage` persists to SQLite and also calls `setState()` on the entity, keeping both in sync
- The JSON config is fully self-describing — a generic UI can derive available states, transitions, events, terminal states (states with no outgoing transitions), and even diagram rendering without any machine-specific code. Adding a new config file is all it takes to support a completely different workflow
- `TimeoutScheduler` interface is minimal (`schedule`, `cancel`, `pullDue`) and the runner handles scheduling/cancellation on state changes automatically — implementing persistent storage (e.g. `SqliteTimeoutScheduler`) requires only three methods, and `processTimeouts()` drives the whole flow
- Multi-machine scoping works naturally — `StateStorageInterface` and `TimeoutScheduler` are per-runner, so separate instances with different storage scopes keep machines isolated without framework support

**What to be aware of:**
- Not published on Packagist — requires a VCS repository in composer.json
- No tagged releases yet — must use `dev-main`
- Built-in persistence is in-memory only — the two built-in implementations are `StatefulStateStorage` (delegates to entity) and `InMemoryStateStorage` (uses `SplObjectStorage`). For production you must implement `StateStorageInterface` — this POC demonstrates it with `SqliteStateStorage`
- The storage interface is keyed by object identity, not by an ID field — `read(object $subject)` receives the entity, so your implementation must extract the identifier (e.g. `$subject->getId()`). This means the interface assumes your entity carries its own identity
- `TimeoutScheduler` ships with only `InMemoryTimeoutScheduler` — timeouts are lost on process restart. The interface (`schedule`, `cancel`, `pullDue`) is clean and easy to implement against a persistent store; this POC demonstrates it with `SqliteTimeoutScheduler`
- Condition data (`$data` array) must be passed explicitly on each `apply()` call
- Mermaid diagram labels include full class names for conditions/commands (can be verbose)
- No built-in audit/history log — implement via PSR-14 events or alongside your storage layer (demonstrated in this POC with both approaches)

**Known limitations:**
- If you modify a JSON config while entries exist in the database, a stored state may no longer exist in the new config, causing an `UnknownStateException`. Use "Delete All" to clear entries after config changes.
- Adding or removing the `machine` column in `SqliteStateStorage` requires a schema migration. In development, `make clean` (or `docker compose down -v`) resets the database.
