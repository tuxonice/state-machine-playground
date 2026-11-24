# Experiment 2: atomicity and failure, findings

Verdict: **adopt with wrapper.** The library gives no atomicity: a failure part-way through a transition leaves state, side effects and timeouts out of step. A DB transaction around `applyTo` fixes the database part. Events and e-mails that go out inline can't be rolled back, so the wrapper also has to hold them until after commit.

## Setup

`experiments/02-atomicity/`. A small machine (`new` to `pending_payment` to `confirmed` to `processing`, with an `auto_cancel` timeout and an `auto_process` onEnter). A failure is injected at one step per scenario. Storage, scheduler and a side-effect ledger share one SQLite connection. Each scenario runs twice:
- **raw:** `applyTo()` as shipped.
- **tx:** the same call inside `beginTransaction()` / `commit()`, with `rollBack()` on any exception.

```bash
docker compose run --rm web php experiments/02-atomicity/run.php
```

## What the library does per transition

Order inside `move()`: dispatch `BeforeTransition`, run the event command, run the transition command, `storage->write()`, dispatch `AfterTransition`. After the whole chain, `scheduleTimeouts()` runs. Nothing is caught: an exception at any step propagates straight to the caller.

## Results

| Scenario | raw result | tx result |
|---|---|---|
| S1 before-listener throws | clean abort, nothing changed | same |
| S2 command throws before its effect | clean abort, nothing changed | same |
| S3 storage write fails after the command ran | **charged, but state still `pending_payment`** | consistent (ledger rolled back) |
| S4 after-listener throws | **state moved, caller gets an exception, no `auto_cancel` scheduled** | state and timeout rolled back, but the e-mail already went out |
| S5 scheduler fails after the state write | **state moved, caller gets an exception, no timeout** | rolled back, e-mail already sent |
| S6 second step of an onEnter chain fails | **state stuck in `confirmed`, charged, caller gets an exception** | whole chain rolled back, "confirmed" e-mail already sent |

## Findings

1. **Command before write (S3).** The command runs before the state is stored. If the write fails, the side effect has happened and the state hasn't moved. A retry runs the command again, which for a charge means a double charge. Commands need idempotency keys, or must only write to the same database transaction (outbox).
2. **A failing after-listener or scheduler orphans the order (S4, S5).** The state is already written, so the order moved, yet the caller sees an exception. Worse, `scheduleTimeouts()` is skipped, so a `pending_payment` order never gets its `auto_cancel` and stays there forever. Listener failures must not be able to break a transition. Either the listener catches its own errors, or the dispatcher does.
3. **onEnter chains are not atomic (S6).** The first step persists, the second fails, and the caller sees an exception for a transition that partly succeeded. onEnter events fire only on entering a state, so nothing retries `auto_process`. The order waits in `confirmed` until someone calls `start_processing`.
4. **A transaction fixes the database side but not the outside world.** In tx mode the e-mail "order is now confirmed" was still sent before the rollback. The library dispatches events inline, mid-transition. A real project needs a dispatcher that buffers events and flushes them after commit, or writes them to an outbox table in the same transaction.
5. **The wrapper can't pick the unit of atomicity inside a chain.** In tx mode a failing stock service rolls back the payment confirmation too. Raw mode keeps the payment and gets stuck. Neither is right for all cases. Preferred approach: keep the chain atomic, but make steps that call unreliable services enqueue work instead of calling the service inline, so they rarely fail. Add a sweep for orders that sit in an intermediate state too long.
6. **Baseline is fine.** A throwing before-listener (S1) or a command that fails before doing anything (S2) leaves everything unchanged. A before-listener works as a veto.

## What we would have to add

1. One transaction around every `applyTo` and `processTimeouts` (storage, scheduler and ledger on the same connection).
2. A post-commit event dispatcher (buffer, then flush after commit), or an outbox.
3. Commands without inline irreversible I/O: idempotency keys or an outbox.
4. A sweep for orders stuck in non-terminal states beyond an expected time.

## Caveats

- The side-effect ledger is a DB table, so a transaction can roll it back. A real payment gateway call can't be rolled back. That is why finding 1 asks for idempotency keys and an outbox.
- This used a single process. Concurrency is covered in experiment 1.
