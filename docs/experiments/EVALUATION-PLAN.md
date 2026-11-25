# Evaluation plan: `tuxonice/state-machine` for real-project use

This playground exists to decide whether `tuxonice/state-machine` can be used in a real project. The experiments below are meant to probe production concerns, not to test our own glue code.

Status: proposed. None of these experiments has been run yet, so the expected outcomes are hypotheses.

## Why the current playground isn't enough

The library's interfaces hint at some weak spots that the current happy-path, single-process demos don't probe:

- `StateStorageInterface` is just `read` and `write`, with no compare-and-swap or version check.
- Guards (`ConditionInterface::check`) and commands (`CommandInterface::run`) only receive `array $data`.
- `TimeoutScheduler::pullDue` both returns and removes the due timeouts.

## Experiments (priority order)

### 1. Concurrency
Two requests apply different events to the same order at the same time. The events must both be valid from the same state in `order-state-machine.json` (`cancel` and `ship` are not: `ship` is only valid from `processing`). Scenarios:
- `confirmed`: `cancel` against `start_processing` (or `auto_process` firing on enter).
- `pending_payment`: `payment_success` against the `auto_cancel` timeout, with the worker and a web request in separate processes. This is the most realistic one.
- `pending_payment`: `payment_success` against `payment_failure`, for example a duplicate or late payment-provider webhook.
- Hypothesis: lost update or illegal state, because storage has no locking or versioning.
- Method: two real PHP processes against the SQLite file.
- Output: what the library offers and what we'd have to add (locking in storage, optimistic versioning).

### 2. Atomicity and failure
A command throws mid-transition, or the storage write fails after the command has already run.
- Can state and side effects diverge?
- Does `AfterTransition` still fire?
- What does a listener exception do to the transition?

### 3. Timeout worker robustness
`pullDue` removes timeouts before they're processed.
- If the worker crashes halfway, are those timeouts lost?
- Can two workers double-fire the same timeout?
- What happens when the system is down for hours and many timeouts come due together?

### 4. Definition evolution: versioned, immutable definitions
Decision: a definition that is live in production is never edited. A change means a new definition version. Orders already running stay on the version they started with until they finish. New orders start on the latest version.

This removes the need for state migration. The experiment checks whether the library supports this model and what we have to build around it.
- Config layout: one immutable file per version, such as `order-v1-state-machine.json` and `order-v2-state-machine.json`, plus a rule for which version is "current" for new orders.
- Registry: `StateMachineRegistry::register($runner, $name)` takes a name, so each version could be registered under its own name such as `order@v1`. Check that two versions with the same internal `name` field don't collide.
- Storage: each order must record its definition version next to its state. `SqliteStateStorage` is scoped by machine name today, so check whether a per-version name is enough or whether it needs an explicit version column.
- Routing: given an order, resolve its runner from the stored version. Starting a new order picks the latest version.
- Guardrail: a test or CI check that fails if a released definition file is modified. For example, compare a hash recorded in a manifest.
- Retirement: a query that shows how many orders are still on each version, so an old version can be removed once the count reaches zero.
- Shared code risk: guards and commands are referenced by class name, so a new version can't change the behaviour of a class that an old version still uses. Check how we version those, or whether we need a policy that says to add a new class instead of changing one.
- Open questions: how long can old versions live, and does anything need to be force-migrated, such as a bug fix or a legal change?

### 5. Real dependencies in guards and commands
Current guards are pure functions of `$data`. Real guards need services (stock repository, payment gateway).
- Wire a PSR-11 container through the resolver, as the README suggests.
- Check whether a guard can see the subject and the actor, not just the data array. This matters for permission-style guards, such as "only a manager may refund".

### 6. Expressiveness limits
Try to model what real workflows need and document what the JSON model can't express:
- parallel tracks (payment and fulfilment at the same time)
- sub-states
- the same event leading to different targets by guard
- re-entering a state

### 7. Scale and performance
Load 10k–100k orders and measure:
- definition parse cost per request, to see whether the machine needs caching
- `availableEvents` cost for list screens
- `processTimeouts` batch time

### 8. Integration shape
- A thin example of a Doctrine-style entity implementing `StatefulInterface`.
- A CI check that validates every config against the library's `schema.json`.

## Deliverable per experiment

Each experiment gets a script or test plus a short findings note in `docs/`, ending in one of these verdicts:

- adopt
- adopt with wrapper
- reject

## Recommended start

Experiments 1–4. Their findings decide adoption, and each gives a clear yes, no or "needs wrapper". Experiment 4 is now a build-and-verify task for the versioning design above, not a failure-mode probe.

## Decisions so far

- Definitions are immutable once live. A change is a new version, and running orders finish on the version they started with (experiment 4).
- A payment that arrives after the order was cancelled does not revive it. The order stays `cancelled` and the event is recorded so the payment can be refunded.
- The playground stays plain PHP. No framework spike for now.
- Experiment 1 is done: adopt with wrapper (see `docs/experiments/01-concurrency-findings.md`).
- Experiment 2 is done: adopt with wrapper (see `docs/experiments/02-atomicity-findings.md`).
- Experiment 3 is done: adopt with wrapper (see `docs/experiments/03-timeouts-findings.md`).
- Experiment 8 is done: adopt (see `docs/experiments/08-integration-findings.md`). An entity needs only `getState()` and `setState()`. The ORM version column prevents the experiment 1 race (Doctrine tested, Eloquent not). A scheduler must hand back real entities. The CI definition check (`experiments/08-integration/check.php`, 9 rules) passes on our real configs.
- Experiment 7 is done: adopt (see `docs/experiments/07-scale-findings.md`). Library overhead is small (8.5 us per transition on the order machine, 0.7 ms to parse a definition). The scale risk is the scheduler: `cancel()` runs on every move, so the timeout table needs indexes on `order_id` and `due_at` (4.2 ms per move at 100k rows without them).
- Experiment 6 is done: adopt, with known limits (see `docs/experiments/06-expressiveness-findings.md`). Flat machine: no wildcard or list sources, one guard per transition, no sub-states or parallel tracks. A reminder written as a self-loop stops the cancel timeout from ever firing, so model reminders as states.
- Experiment 5 is done: adopt with wrapper (see `docs/experiments/05-dependencies-findings.md`). DI through PSR-11 works. Guards and commands only get `array $data`, never the subject, so one entry point must build `$data` from the subject and the actor.
- Experiment 4 is done: adopt (see `docs/experiments/04-versioning-findings.md`). The library supports versioned definitions as designed. `SqliteStateStorage` does not scope reads by machine name, so the version must be stored explicitly on each order.

## Overall verdict after experiments 1 to 8

**Adopt with a wrapper.** No experiment found a blocker. The library is a correct, small, fast, framework-agnostic flat state machine. It leaves the following to the application, and every one of them needs code we write:

1. **Concurrency and atomicity** (experiments 1, 2): a lock or version check, a transaction per transition, side effects after commit.
2. **A reliable timeout worker** (experiment 3): lease and acknowledge instead of `processTimeouts()`, batching, dead-lettering, indexed scheduler table (experiment 7). Since done, see "Fixed since the experiments".
3. **Versioned, immutable definitions** (experiment 4): works as designed, with a version stored on every order and a release fingerprint check.
4. **One entry point that builds `$data`** (experiment 5): order id and actor set from the subject, never by callers.
5. **Definition rules in CI** (experiments 6, 8): the checker in `experiments/08-integration/`.

Not tested: Eloquent and Laravel specifics, and real database latency under load.

## Fixed since the experiments

The findings describe the code as it was when each experiment ran. Several changes have landed since, so re-running experiments 2, 3, 5 and 7 now gives different results, and experiment 6 has new options:

- **Library, [tuxonice/state-machine#8](https://github.com/tuxonice/state-machine/pull/8):** timeouts are scheduled right after each state is stored, before `AfterTransition`. `processTimeouts()` keeps going past a failing timeout and reports all failures in a `TimeoutProcessingException`. The optional `AcknowledgingTimeoutScheduler` (`claimDue()`, `acknowledge()`) keeps a timeout until it has been dealt with.
- **Playground, `SqliteTimeoutScheduler`:** implements `AcknowledgingTimeoutScheduler` with a lease (60 s), batches (100), dead-lettering after 3 attempts, and indexes on `order_id` and `due_at`.
- **Library, [tuxonice/state-machine#9](https://github.com/tuxonice/state-machine/pull/9):** a guard or command that needs constructor arguments and is not in the container fails with a clear `ResolutionException`. `validateClasses()` checks every class a definition names, up front. The playground's `DefinitionClassesTest` runs it on every definition.
- **Library, PSR-20 clock:** the runner takes an optional `ClockInterface`, so timers can be tested without a decorator.
- **Library, `keepTimeouts`:** a self-transition flagged `"keepTimeouts": true` keeps the state's running timeouts, which avoids experiment 6's reminder trap. The playground's definition checker (R4) accepts it.
- **Library, backlog draining:** one `processTimeouts()` call keeps claiming batches until nothing new is due (up to `maxBatches`), so a worker run once a minute does not fall behind (experiment 3, T4: 3000 of 3000 in one call).
- **Library, `TransitionContext` (breaking):** conditions and commands receive a context with the subject and the transition instead of only the data array, closing experiment 5's identity gap for code that reads the subject. The playground's guards, command and experiments use the new signatures.

Each affected findings note starts with a "Fixed since" section that lists what changed. Item 2 of the verdict above is now covered by the library plus the playground scheduler. Still open from the same experiments: experiment 1's race (the per-order lock), experiment 2's side effects before commit, and the guard on a timeout event that drops it (experiment 3, T5).

## Open question

What is the real use case? Many concurrent workers, long-lived entities, or regulated audit trails would change the priorities above.
