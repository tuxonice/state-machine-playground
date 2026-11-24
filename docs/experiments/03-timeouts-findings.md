# Experiment 3: timeout worker robustness, findings

Verdict: **adopt with wrapper.** `processTimeouts()` is fine for a single reliable worker with no failures. It is not safe for production: a crash or one failing order permanently loses timeouts, and two workers apply the same timeout twice. A lease-and-ack loop in our own code fixes all three.

## Fixed since

The lease-and-ack loop now lives in the library and the playground, not in our own loop:
- [tuxonice/state-machine#8](https://github.com/tuxonice/state-machine/pull/8) added `AcknowledgingTimeoutScheduler`. `processTimeouts()` uses it when available and keeps going past a failing timeout.
- The playground's `SqliteTimeoutScheduler` implements it, with a lease, batches, dead-lettering and indexes.

The raw mode of this experiment (the library's `processTimeouts()` with that scheduler) now gives:

| Scenario | When the experiment ran | Now |
|---|---|---|
| T1 worker killed after 10 of 50 | 40 orders stuck with no timeout left | 40 still scheduled, claimable after the lease |
| T2 poison order | batch aborted: 24 cancelled, 26 stuck | 49 cancelled, the poison order's timeout kept for retry, failure reported in a `TimeoutProcessingException` |
| T3 two workers at once | 12 timeouts applied twice | 0 applied twice |

T4 was not re-run. T5 is unchanged: a timeout whose guard fails is still dropped, not retried. In "What we would have to add":
- Items 1 and 2 are done. The library re-checks the order's state before applying a claimed timeout.
- Item 3 is partly done: dead-lettered rows are kept in the table, but nothing alerts on them yet.
- Items 4 and 5 still apply.

## Setup

`experiments/03-timeouts/`. The real order machine (`auto_cancel` after 30 minutes in `pending_payment`), real `SqliteStateStorage` and `SqliteTimeoutScheduler` in a shared SQLite file, workers as separate PHP processes. Each of T1 to T4 runs two ways:
- **raw:** `$runner->processTimeouts($now)`, the library loop with `pullDue()`.
- **safe:** our loop: claim a batch with a lease, apply each timeout inside its own `try/catch`, delete the row only after success (ack), skip rows whose order already left the scheduled state, dead-letter a row after 3 failed attempts.

```bash
docker compose run --rm web php experiments/03-timeouts/run.php
```

## Results

| # | Scenario | raw | safe |
|---|---|---|---|
| T1 | Worker killed after 10 of 50 | 10 cancelled, **40 orders stuck in `pending_payment` with no timeout left, ever** | 10 cancelled, 40 still scheduled. After restart and lease expiry: **50/50 cancelled, 0 duplicates** |
| T2 | Order ORD-25 fails permanently, 50 due | exception aborts the batch: 24 cancelled, **26 stuck** (the poison order and everything after it) | 49 cancelled, the poison row retried 3 times then dead-lettered (1 row left for alerting) |
| T3 | Two workers at once, 50 due | all cancelled, but **12 timeouts applied twice** (commands run and events dispatched twice) | 50 cancelled, 0 duplicates, work split between workers |
| T4 | Backlog of 3000 due at once | 15 s in one call, 8 MB | 30 s, 4 MB (batches of 100) |
| T5 | Due timeout whose guard fails | `Blocked`, **the timeout is gone and the order stays `open` forever** | not tested (same library behaviour applies) |

## Why it happens

- `processTimeouts()` calls `pullDue()`, and `pullDue()` deletes every due row before any of them is processed. Between the delete and the work there is no record of what is still to do (T1, T2).
- `pullDue()` is a `SELECT` followed by a `DELETE`, not an atomic claim, so two workers can both pull the same rows (T3).
- Nothing in the loop catches an exception. The first failure aborts the batch, and the rest of the batch is already deleted (T2).
- The library documents that a timeout whose condition does not pass is dropped, not retried (T5). A timeout on a guarded event therefore only gets one chance.

## What we would have to add

1. **A lease-based scheduler.** The `TimeoutScheduler` interface has no ack, so `processTimeouts()` can't use it. We need our own loop on a store with `claim()` and `ack()` (see `LeasingScheduler` and the safe branch of `worker.php`).
2. **Idempotent handling.** A retry after a crash between the state write and the ack is safe only because the loop re-checks the order's state first. Keep that check.
3. **Dead-letter handling.** Rows that exhaust their attempts need an alert and a manual path.
4. **No guards on timeout events** unless the loss of the timeout is acceptable. Put the check in the handler and reschedule, or model it as a transition to a review state.
5. **Combine with experiment 1 and 2 findings.** The safe loop still needs the per-order lock and the transaction around `applyTo`.

## Caveats

- Timings are from SQLite in Docker with autocommit writes. The safe loop is slower here because every claim and ack is a separate write. Batching acks would narrow the gap. Treat the numbers as relative.
- The crash is simulated with `exit(137)` right after the 10th state write. It leaves no output and runs no cleanup, but it is not a real `SIGKILL` of a long-running daemon.
- The 8 MB vs 4 MB difference is small at 3000 rows. The raw loop loads all due rows at once, so memory grows with the backlog.
