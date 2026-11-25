# Experiment 6: expressiveness limits, findings

Verdict: **adopt, with known limits.** The library is a plain flat state machine: one state per subject, static targets, one guard per transition. Our order and lead flows fit it. Everything else that real workflows need can be built around it, but each workaround is code and convention we own. One of them hides a trap: a reminder written as a self-loop silently stops the cancel timeout from ever firing.

## Fixed since

Two library changes (in `tuxonice/state-machine` `main`, commits `7fc2ff9` and `8b32615`) address findings here:

- **The reminder trap (X4a) can now be avoided.** A self-transition flagged `"keepTimeouts": true` leaves the state's running timeouts alone, so a reminder no longer pushes the cancel away. The flag is only allowed on a self-transition. The default is unchanged, and this experiment does not use the flag, so re-running X4 still shows the trap. The playground's definition checker (rule R4 in `experiments/08-integration/`) accepts a self-loop flagged `keepTimeouts`.
- **The runner takes a clock.** `StateMachineRunner` accepts an optional PSR-20 `ClockInterface`, so the `SimClockScheduler` decorator used here is no longer needed for timer tests ("What we would have to add" item 1).

The other limits are unchanged: no wildcard or list sources, one guard per transition, no sub-states or parallel tracks, timers measured from entering a state.

## Setup

`experiments/06-expressiveness/run.php`. Each probe feeds a definition to `DefinitionReader` and the runner and records what is accepted, what is rejected, and what happens at run time. For timers I used a decorator scheduler that maps the runner's system-clock deadlines onto a simulated clock, because the runner has no clock to inject.

```bash
docker compose run --rm web php experiments/06-expressiveness/run.php
```

## What the model can and can't say

| # | Need | Result |
|---|---|---|
| X1 | "Cancel from any state" | `"source": "*"` is rejected at load ("source '*' does not exist in states list"). A list of sources is rejected by the schema. One transition per source state is the only way (3 sources, 3 transitions). |
| X2 | Two guards on one transition (AND) | A list is rejected by the schema. The string `"A && B"` is **accepted at load** and fails with `ResolutionException` on first use, because it is read as a class name. The workaround is one composite guard class per combination, since a guard can't take arguments from the JSON. |
| X3 | Self-transition (`s` to `s`) | Works: `Moved`, state unchanged, command runs once. |
| X3 | An `onEnter` event that loops back to its own state | Stops with `OnEnterLoopException`, but only after **50 command runs**. All 50 side effects have already happened. |
| X4 | Reminder at 15 min and cancel at 30 min in the same state, reminder written as a self-loop | **The cancel never fires.** Every move (including a self-loop) cancels and reschedules all timeouts of the state. After 96 simulated minutes the order was still `pending` with 6 reminders sent. |
| X4 | Same, reminder as its own state (`pending` -15 min-> `reminded` -15 min-> `cancelled`) | Works: reminded at 16, cancelled at 31. |
| X4 | Timer semantics | Measured from when the state is entered, and the next timeout is stamped from when the worker processed the previous one. Worker lateness adds up (the cancel landed at minute 31, not 30). There is no way to say "cancel 30 minutes after the order was placed, however often it re-enters a state". |
| X5 | Parallel tracks (payment and shipment independent, order completes when both are done) | Not expressible in one machine except as a product of the tracks: **20 states and 31 transitions** for tracks of 4 and 5 states, against 9 states and 7 transitions as two machines. Two machines plus a coordinator works: completion happened exactly once whichever track finished last, and not at all when payment never happened. |
| X6 | Pause, then resume to the state the order came from | Works, but costs one guarded resume transition and one guard class per origin state, and the caller has to remember the previous state itself. |

Also by design, and not something an experiment can show: targets are static strings in the JSON, so a target chosen at run time is not expressible. Branching is limited to guards choosing among targets that are written down. History ("go back") and sub-states do not exist as concepts.

## The coordinator for parallel tracks

The library has no fork or join. In the experiment, `payment`, `shipment` and `order` are three machines in the registry. An `AfterTransition` listener on the two child machines calls `complete` on the parent after every child transition. The parent's guard cannot see the children (guards get no subject), so the listener passes both child states in `$data` and the guard checks them (this follows the convention from experiment 5).

## Consequences for the order machine

- Our order flow has no real parallel tracks today. If payment and fulfilment ever need to be independent, use separate machines and a coordinator, not a product machine.
- A payment reminder must be a state, not a self-loop. The simplest rule: never put two timeout events on a state where one of them leaves the state unchanged.
- "Cancel from many states" costs one transition per state. Add the transition to the definition whenever a new state is added, and let the guardrail from experiment 4 catch edits to released versions.
- Do not use `onEnter` on an event whose transition can return to the same state.

## What we would have to add

1. **A convention and a test for timers.** For every state with timeouts, a simulated-clock test that walks the timeline. The decorator in this experiment is the starting point.
2. **A coordinator service** for any multi-track process, wrapped in the lock and transaction from experiments 1 and 2. Two tracks finishing at the same moment must not double-complete or miss completion.
3. **A definition lint in CI** that rejects:
   - a self-loop on a state that also has a timeout event (X4a)
   - an `onEnter` event whose transition returns to the same state (X3)
   - any condition string that is not a single existing class (X2)

## Caveats

- X5 ran in one process. Concurrent child completions are the experiment 1 problem again, applied to the parent.
- The simulated clock comes from a decorator I wrote. The real runner uses the system clock when it schedules, so any test of timers needs the same trick or real waiting.
- The product-machine count comes from a generated definition that I counted but did not run through the runner.
