# Experiment 5: real dependencies in guards and commands, findings

Verdict: **adopt with a thin wrapper.** Dependency injection through a PSR-11 container works, and guards can make real decisions (permissions, service calls, branching). The gaps are about what a guard or command is given: only an `array $data` that the caller builds, never the subject. That needs a small convention enforced in one place.

## Setup

`experiments/05-dependencies/run.php`. A small machine with guards backed by services (`InStock` calls a stock service, `LowRisk` a fraud service), a permission guard (`IsManager`), a command with an injected mailer (`NotifyCommand`), and an `onEnter` event. Services are wired through a minimal PSR-11 container passed to `ConditionResolver` and `CommandResolver`.

```bash
docker compose run --rm web php experiments/05-dependencies/run.php
```

## Results

| # | Question | Result |
|---|---|---|
| D1 | Does the container inject services into guards and commands? | Yes. A guard built by the container with its own service decided correctly. |
| D1 | Missing registration | A guard with a constructor argument the container doesn't know fails with a plain `ArgumentCountError` (the resolver falls back to `new $class()`), not a library exception, and only when that transition is first evaluated. |
| D1 | Missing class or wrong type in the definition | The definition loads without complaint. `ResolutionException` is thrown only when the guard is first evaluated, so a typo in a class name in the JSON surfaces in production on first use. |
| D2 | What do guards, commands and listeners receive? | Guards and commands receive exactly the caller's `$data`, unchanged. Their signatures are `check(array $data)` and `run(array $data)`: **no subject, no actor**. Only listeners (`AfterTransition`, `BeforeTransition`, `TransitionBlocked`) get the subject object, plus `$data`. |
| D3 | Permission guard (`only a manager may refund`) | Works through `$data['actor']`. A clerk's `refund` returns `Blocked`, the state stays, and a `TransitionBlocked` event is dispatched. The manager's moves to `refunded`. |
| D3 | Do `availableEvents` listings respect it? | **No.** `availableEvents`, `availableEventsFor` and `availableManualEventsFor` look at the state only and never evaluate guards. Both clerk and manager see `refund`. A UI has to filter with `canApplyTo($subject, $event, $data)` per event. |
| D4 | How often is a service-backed guard called? | 0 times by the listings, 1 by `can()`, 1 by `applyTo()`. Checking `can()` and then applying runs the guard twice, so the answer can change in between. One `pay` that chains into an `onEnter` event ran the stock guard once. |
| D5 | Commands with injected services | Works: the injected mailer recorded the refund mail, using actor details from `$data`. |
| D6 | One event, two targets chosen by a service-backed guard | Works. A low fraud score led to `confirmed` (then `auto_pack` via onEnter, ending in `packed`), a high one to `manual_review`. The first passing transition wins, so an unconditional transition acts as the else branch. |

## Finding: identity in `$data` is not tied to the subject

I hit this by accident in my own test: I refunded order `P-2` using data that said `orderId: P-1`. The library accepted it, the guard and the command saw `P-1`, and the mail was recorded for `P-1`. Because guards and commands never see the subject, the order id and the actor travel in `$data`, and the library can't check that they match the subject. A caller that builds `$data` by hand can get this wrong silently.

## What we would have to add

1. **One entry point that builds `$data`.** A service method such as `apply($subject, $event, $actor, $extra)` that always sets the order id from the subject and the actor from the authenticated user, and does not let callers override them. No direct `applyTo` calls elsewhere.
2. **A startup or CI check that every guard and command in every definition resolves.** Walk each definition, resolve each class through the real container, and fail the build on a missing class, wrong interface or unregistered dependency. This turns the first-use failures from D1 into build failures.
3. **Event filtering for UIs.** Never show `availableEventsFor()` directly. Filter with `canApplyTo` and the current actor.
4. **Guards written as idempotent reads.** They run for `can()` and again for `applyTo()`, so they must be side-effect free and cheap. A guard that reserves stock or consumes a token would act twice.
5. **Re-check at apply time.** A `can()` result is not a promise. The decision that counts is the one `applyTo()` makes, and the caller must handle `Blocked`.

## Caveats

- The container is a minimal hand-written PSR-11 implementation. A real framework container with autowiring would resolve the `ArgumentCountError` case for concrete classes, but an unbound interface dependency would still fail with the container's own exception, at first use.
- The services are in-memory stand-ins. Slow or failing real services in guards are covered by experiment 2 (failure) and by item 4 above (guards should stay cheap).
- A permission guard is only as trustworthy as the `$data['actor']` it reads. Item 1 is what makes that safe.
