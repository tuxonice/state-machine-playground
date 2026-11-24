# Experiment 4: versioned, immutable definitions, findings

Verdict: **adopt.** The library supports the model without changes: two versions of one definition run side by side, each order stays on the version it started with, and new orders pick up the latest. The wrapper needed is small (a catalog, a version pinned to each order, and a release guardrail). One existing piece of playground code, `SqliteStateStorage`, does not scope state by machine name and must not be used as the version boundary.

## Decision being verified

A definition that is live in production is never edited. A change means a new version file. Orders already running finish on their version, new orders start on the latest.

## Setup

`experiments/04-versioning/`:
- `definitions/order-v1.json` and `order-v2.json`. Both have `"name": "order"`. v2 adds an `awaiting_review` step after payment, a new guard, and a 60 minute `auto_cancel` instead of 30.
- `src/Catalog.php` loads `<name>-v<N>.json` files and registers each runner as `order@v<N>`.
- `src/VersionedStore.php` writes the version once when an order is created. Only the state is ever updated.
- `src/OrderService.php` picks the runner from the order's stored version. New orders get the latest.
- `src/Fingerprints.php` is the release guardrail.

```bash
docker compose run --rm web php experiments/04-versioning/run.php
```

## Results (23 checks, all pass)

| # | What was checked | Result |
|---|---|---|
| C1 | Registry with two versions that share the internal name | `register($runner)` with the default name rejects v2 (`A state machine named 'order' is already registered`). Registering under explicit keys `order@v1` and `order@v2` works. |
| C2 | Deploy of v2 while v1 orders are in flight | A new order starts on v2. In-flight order A stays on v1, offers only v1 events, and finishes its v1 flow after v2 is live. A v1 order's timeout stays at 30 minutes, a v2 order's follows v2. |
| C3 | A v2-only event sent to a v1 order | `UnknownEventException` ("Event 'approve_review' does not exist"), not a silent no-op. The v1 guard still behaves as v1. |
| C4 | Retirement | Terminal states are derivable from the definition (states with no outgoing transition). Counting orders in non-terminal states per version tells when a version can go (v1 reached 0). If a version is removed while an order still points at it, the lookup fails loudly (`State machine 'order@v1' is not registered`). |
| C5 | Release guardrail | Fingerprints of the definition file and every referenced guard and command source file. Detects an edit to a released JSON file, detects an edit to a shared guard class (flagging v1), leaves v2 alone when it uses a new guard class, and is unaffected by adding a new version file. |

## Finding: `SqliteStateStorage` does not scope reads by machine

`order_id` is the only primary key and `read()` selects by `order_id` alone. A state written under machine `order@v1` is returned when read under `order@v2`. Only `listOrders()` filters by machine. This is existing playground code, not the library, and the project notes describe it as "scoped by machine name". Practical consequences:
- The version must be stored explicitly on the order (as `VersionedStore` does). Giving each version its own machine name in the existing storage does not isolate anything.
- The same order id used in two different machines (for example `order` and `lead`) would collide.

I did not change `SqliteStateStorage`.

## What we would have to build

1. **A version column set once at order creation**, never updated. Route every call through one service that resolves the runner from it.
2. **A catalog** that registers each definition file under `<name>@v<N>`. The registry rejects duplicate names, so explicit keys are required.
3. **The guardrail in CI.** Record fingerprints at release time, and fail the build when a released definition or a class it references changes. This also enforces the rule "new behaviour means a new guard or command class, not an edit".
4. **A retirement check.** A query of orders per version in non-terminal states. Removing a version is allowed only at zero.
5. **Webhook mapping per version.** A provider event that has no meaning in an old version raises `UnknownEventException`. The webhook layer must catch it, log it, and not retry forever.

## Not covered

- **Forced migration.** The model has no way to move a running order to a new version (for example for a bug fix or a legal change). That would be a separate, explicit migration per case.
- **What a guard calls.** The fingerprint covers the guard's class file only. If a guard calls a repository or service whose behaviour changes, the guard changes without its file changing.
- **Concurrency and failure.** Experiments 1 to 3 still apply per order, whatever its version.
- **Everything ran in one process** on in-memory SQLite and an in-memory scheduler.
