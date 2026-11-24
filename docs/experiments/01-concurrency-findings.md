# Experiment 1: concurrency, findings

Verdict: **adopt with wrapper.** The library has no protection against concurrent events on the same subject. A per-subject lock around each transition fixes it, but the lock has to live in our code.

## Setup

`experiments/01-concurrency/`. A `payment_success` webhook races the `auto_cancel` timeout worker on an order in `pending_payment`. They run as two separate PHP processes sharing one SQLite file (`SqliteStateStorage` plus `SqliteTimeoutScheduler`) and start at the same instant. 100 iterations per setting.

The read delay is a sleep after every storage read. It models the time a real app spends between reading the state and writing it back (service calls in guards, remote DB, slow listeners).

Run it:

```bash
docker compose run --rm web php experiments/01-concurrency/run.php 100 0,5,20 none   # unprotected
docker compose run --rm web php experiments/01-concurrency/run.php 100 0,5,20 lock   # per-order flock
```

## Results

Unprotected (`LOST_UPDATE` means both events were reported as `Moved` from `pending_payment`):

| Read delay | webhook only | timeout only | LOST_UPDATE |
|---|---|---|---|
| 0 ms | 3 | 1 | 96 |
| 5 ms | 71 | 0 | 29 |
| 20 ms | 99 | 0 | 1 |

In every lost update the second writer overwrote the first. The final state was `confirmed` or `cancelled`, depending on who wrote last. Both transitions had already run their commands and dispatched `AfterTransition`, so the customer can get a payment confirmation and a cancellation notice for the same order, and a paid order can end up `cancelled`.

With a per-order lock around both paths: 0 lost updates in 300 runs. The webhook won every run, so the "timeout wins, then a late payment arrives" ordering was not exercised. In that ordering `payment_success` from `cancelled` returns `NoTransition`. Decision: that is the intended outcome, a late payment never revives a cancelled order. The webhook layer must still record the ignored event, so the money taken can be refunded outside the machine (the `cancelled` to `refunded` transition already exists).

## Why it happens

`StateMachineRunner::transition()` reads the state, evaluates the guard, runs commands, dispatches events, and then calls `storage->write()`. There is no atomic step between the read and the write, and `StateStorageInterface` has only `read` and `write`, with no compare-and-swap or version. `processTimeouts()` has its own check-then-act: it reads the state to skip stale timeouts, then `applyTo()` reads it again.

## Caveats

- The 0 ms row overstates the real frequency. The start barrier forces both processes to arrive together. In production the window is the time between read and write, which is narrow but never zero, and it grows with every slow guard or listener.
- SQLite with `flock` is a stand-in. In a real project the lock would be a row lock (`SELECT ... FOR UPDATE`) or an advisory or cache lock.

## What we would have to add

1. **A per-subject lock around `applyTo`.** Every caller (web request, webhook, worker) must go through one service that takes the lock. A caller that bypasses it reintroduces the race.
2. **Our own timeout loop.** `processTimeouts()` gives no hook to lock per subject. In lock mode `worker.php` reimplements it with `pullDue`, a state re-check and `applyTo` under the lock. The alternative is one global lock around `processTimeouts()`.
3. **Optionally, a version check in storage.** A `version` column and `UPDATE ... WHERE version = ?` would catch races even where a lock is forgotten. The interface can't express it today, so it needs a storage-specific method that throws on conflict.

## Related findings for later experiments

- `pullDue()` deletes timeouts before they are processed (experiment 3). A crash after the pull loses them.
- Side effects (commands, events) run before the state write, so a failed write leaves them already done (experiment 2).
