# Experiment 8: integration shape, findings

Verdict: **adopt.** A framework entity becomes a subject by implementing two methods, and an ORM's own optimistic locking answers the concurrency problem from experiment 1. The integration work is in three places: the persistence boundary, the timeout scheduler (it has to hand back real entities) and a CI check, which I built and which runs against our configs.

## Part A: a Doctrine ORM entity as the subject

`experiments/08-integration/run.php`. Doctrine ORM 3.8 and DBAL 4.6 with SQLite, installed in the experiment's own `composer.json`, so the playground's dependencies are untouched.

```bash
docker compose run --rm web composer update -d experiments/08-integration   # once
docker compose run --rm web php experiments/08-integration/run.php
```

| # | Question | Result |
|---|---|---|
| A1 | What does an entity need? | Implement `StatefulInterface` (`getState()` and `setState()`) and use the default `StatefulStateStorage`. No custom storage class. The definition version (experiment 4) and an `#[ORM\Version]` column sit on the entity. |
| A1 | When is state persisted? | `applyTo()` only changes the entity in memory. The database keeps the old state until `flush()`. The timeout row, in contrast, is written immediately, because the scheduler uses the connection directly and bypasses the unit of work. |
| A2 | What if something fails between `applyTo()` and `flush()`? | The in-memory entity says `confirmed` while the database says `pending_payment`, and a later `flush()` would save the wrong state. After any failure the `EntityManager` must be cleared or closed, never reused. |
| A3 | Experiment 1's race, with the ORM version column | Two requests load the same order at version 2. The first flush succeeds. The second raises `OptimisticLockException`, and the database keeps the first writer's state. No lost update, and no lock code of ours. A retry after reload applies `payment_success` to the now-cancelled order and gets `NoTransition`, which is the decision we made (a late payment never revives a cancelled order). |
| A3 | What the version column does not protect | The payment command had already run before the second flush failed. Commands and events run before `flush()`, so they must be deferred or idempotent (experiment 2). |
| A4 | Timeouts when the subject is an entity | With a scheduler that hands back a bare id object (like the playground's `SqliteTimeoutScheduler`), a due timeout is silently skipped: the stub has no state, so the "is the order still in the scheduled state" check fails. The row is already deleted, so the timeout never fires. A scheduler that re-hydrates the managed entity (`DoctrineTimeoutScheduler` in the experiment) works. |
| A4 | Does `processTimeouts()` persist? | No. It changes the entities in memory only. The worker must flush, per item, and handle conflicts. |

## Part B: CI check for definitions

`experiments/08-integration/check.php` with the rules in `src/DefinitionChecker.php`. Each rule comes from an earlier finding.

| Rule | Checks | Source |
|---|---|---|
| R1 | Schema and validation | library validator |
| R2 | Every guard and command exists, implements the right interface, and its constructor arguments are registered (with `--container`) | experiment 5 |
| R3 | A condition is a single class name (rejects `"A && B"`) | experiment 6 |
| R4 | A state with timeouts has no self-loop (timers would restart) | experiment 6 |
| R5 | No cycle of `onEnter` transitions (an unconditional one is an error) | experiment 6 |
| R6 | No transition after an unconditional one for the same state and event | library semantics |
| R7 | A timeout event has no guard (a failing guard drops the timeout) | experiments 3 and 6 |
| R8 | Every state is reachable from the initial state | hygiene |
| R9 | Every event is used, and every transition uses a defined event | hygiene |

```bash
docker compose run --rm web php experiments/08-integration/selftest.php   # each rule fires on its fixture
docker compose run --rm web php experiments/08-integration/check.php      # default: config/*.json
```

- All nine fixtures trigger their rule and the clean fixture produces nothing.
- Our real `order-state-machine.json` and `lead-state-machine.json` pass with no errors and no warnings.
- Exit codes: 1 on any error, 0 on warnings only, 1 on warnings with `--strict`.
- Not included: the release fingerprint comparison from experiment 4. That code exists in `experiments/04-versioning/src/Fingerprints.php` and belongs in the same CI job.

## Laravel

You said Laravel is your only target, and I tested Doctrine because the plan asked for a Doctrine-style entity. **I did not test Eloquent.** The findings carry over like this:

- Likely the same: a model implements `StatefulInterface` through `getState()` and `setState()`. After a failure the model's attributes are still changed, so reload it (`refresh()`). The scheduler must return real models (`find()`), not bare ids.
- Different: Eloquent has no unit of work. `save()` writes at once, so there is no flush boundary and the in-memory and database states stay closer together. It also has no built-in optimistic locking, so experiment 1's fix would be `lockForUpdate()` inside a `DB::transaction()`, or a hand-written `WHERE version = ?` update. That is the lock from experiment 1, in database form.
- Worth a short spike if Laravel stays the target. It would cover the model, the scheduler and a queued job in one place.

## What we would have to add

1. **A persistence boundary around `applyTo()`**: one transaction per transition that contains the state change, the scheduler writes and the flush or save.
2. **Clear the unit of work after a failure** (Doctrine), or reload the model (Eloquent).
3. **A scheduler that re-hydrates real entities**, and a worker that saves and handles conflicts for each item.
4. **Concurrency control**: the ORM version column in Doctrine, a row lock in Laravel, plus deferred side effects either way.
5. **The CI job**: run `check.php` (and the fingerprint comparison) on every change to a definition.

## Caveats

- Doctrine with SQLite on one connection pair, not a production database. The interleaving in A3 is deterministic, so it proves detection, not a rate.
- The checker's R2 only reports constructor arguments as a warning without `--container`. Its precision depends on how the real container is passed in.
- The checker reads definitions only. Whether a guard behaves correctly is still a test, not a lint.
