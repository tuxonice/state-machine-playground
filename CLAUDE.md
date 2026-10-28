# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A PHP 8.2 proof-of-concept evaluating the external `tuxonice/state-machine` library (namespace `Tlab\StateMachine`, installed from VCS `dev-main`). State machines are defined in JSON; the PHP here is thin glue (storage, listeners, guards, commands) plus a CLI demo and a single-file web UI. Project namespace: `Tlab\StatemachinePlayground\` → `src/`.

## Commands

Docker is the primary workflow (see `make help`); without Docker use `composer install` and run the same commands directly.

```bash
make up                                   # web UI at http://localhost:8080 (or: php -S localhost:8080 -t public/)
make test                                 # docker compose run --rm web vendor/bin/phpunit --testdox
vendor/bin/phpunit                        # all tests locally
vendor/bin/phpunit --testsuite Unit       # or Integration
vendor/bin/phpunit tests/Unit/OrderTest.php
vendor/bin/phpunit --filter testName
php bin/cli.php [--config config/lead-state-machine.json]   # interactive CLI demo
make diagram / make diagram-leads         # print Mermaid diagram
make clean                                # removes containers and data/
```

## Architecture

- **`config/*-state-machine.json`** is the source of truth for states, events, transitions, guard `condition` FQCNs, `command` FQCNs, `timeout`s and `onEnter` auto-transitions. `public/index.php` auto-discovers every `*-state-machine.json` in `config/`, so adding a machine or state usually needs no PHP changes. Guards implement `Tlab\StateMachine\Conditions\ConditionInterface` (`src/Conditions`), commands implement `CommandInterface` (`src/Commands`).
- **`Bootstrap::createRunner()`** is the single factory for `StateMachineRunner`: reads a config, wires `OrderEventDispatcher` (PSR-14), and optionally a timeout scheduler and `StateStorageInterface`. Used by CLI, web UI and tests. Without a storage it defaults to the library's `StatefulStateStorage` (state lives on the `Entity\Order` via `StatefulInterface`).
- **Persistence (`src/Storage`)**: `SqliteStateStorage` (per-machine state + transition history, scoped by machine name) and `SqliteTimeoutScheduler` (pending timeouts; "simulate time" fires due ones via `processTimeouts()`). The web UI stores both in `data/orders.sqlite` (gitignored, created on demand, bind-mounted from the host).
- **Events**: `OrderEventDispatcher` forwards PSR-14 `BeforeTransition`/`AfterTransition`/`TransitionBlocked` to `TransitionLogger` (audit log) and `NotificationSimulator`. The web UI log panels are session-scoped; history is in SQLite.
- **`public/index.php`** is the whole web UI (routing, handlers, HTML, Mermaid rendering) in one file; selected config/order are kept in `$_SESSION`. `bin/cli.php` is the equivalent terminal demo.
- **Tests**: `tests/Unit` for individual classes, `tests/Integration` for end-to-end flows per scenario (happy path, cancellation, timeouts, onEnter, returns, SQLite). Design notes/plan live in `docs/superpowers/plans/`.

## Rules

- Always run commands (tests, CLI, composer, PHP scripts) through Docker (`docker compose run --rm web ...` or the `make` targets), never directly on the host.
- Use Conventional Commits for commit messages (e.g. `feat:`, `fix:`, `docs:`, `chore:`, `test:`, `refactor:`).
