# Experiment 7: scale and performance, findings

Verdict: **adopt.** The library's own cost is small for realistic definitions. The scale risks sit in what we plug into it: a scheduler whose `cancel()` scans the whole table, and loading every due timeout at once. Parsing a definition is cheap enough to ignore for one version, and cheap to cache for several.

## Setup

`experiments/07-scale/run.php`. Medians of 3 to 5 repeats, in Docker on one machine, so compare numbers with each other rather than reading them as absolute. Large definitions are generated: a chain of N states, each with three transitions.

```bash
docker compose run --rm web php -d memory_limit=2G experiments/07-scale/run.php
```

## Results

### Parsing a definition (once per request unless cached)

| Definition | `readFile` (validate + build) | `unserialize` of a cached model |
|---|---|---|
| order (12 states, 17 transitions) | 0.70 ms | 18 us (about 40 times faster) |
| 101 states, 300 transitions | 3.3 ms | 0.18 ms |
| 1001 states, 3000 transitions | 39 ms | 2.5 ms |
| 3001 states, 9000 transitions | 166 ms | 8.4 ms |

`json_decode` alone is about 2% of the read: the time goes to schema validation and building the model. Using a fresh `DefinitionReader` per call costs the same as reusing one (about 630 us in a separate check), so the reader itself is cheap.

### Per-call cost of the runner

| Definition | `applyTo` | `can()` | `availableEvents()` |
|---|---|---|---|
| order (17 transitions) | 8.5 us | 1.5 us | 0.7 us |
| 3000 transitions | 438 us | 150 us | 82 us |
| 9000 transitions | 1.40 ms | 0.50 ms | 0.25 ms |

Cost grows linearly with the number of transitions, because the model scans them on every call. At realistic sizes this is irrelevant. A list screen of 1000 orders with guard-aware event filtering took 6 ms in total (6 us per order).

### Timeout backlog

- 100k timeouts due at once, in memory: `processTimeouts()` applied all 100k in **1.13 s**, using about 92 MB above baseline (peak 121 MB). The cost of the loop itself is small. The memory comes from `pullDue()` returning every due row at once, which supports the batching from experiment 3.
- **Seeding was quadratic.** Creating the 100k orders took 273 s. A follow-up check showed 0.59 s for 5k orders, 2.3 s for 10k and 9.1 s for 20k: it quadruples when the count doubles. The cause is `InMemoryTimeoutScheduler::cancel()`, which filters the whole list, and every move calls `cancel()`. That class is documented as a test double. The lesson is that any scheduler where `cancel(subject)` is a scan behaves the same way.

### Our SQLite scheduler as the table grows

| Rows | cancel, no index | cancel, indexed | `pullDue` (nothing due), no index | `pullDue`, indexed |
|---|---|---|---|---|
| 1,000 | 35 us | 8.5 us | 40 us | 11 us |
| 10,000 | 280 us | 8.9 us | 308 us | 11 us |
| 100,000 | **4.2 ms** | 8.4 us | **4.1 ms** | 10 us |

`scheduled_timeouts` in `src/Storage/SqliteTimeoutScheduler.php` has no index on `order_id` or `due_at`. Every `applyTo` that moves an order calls `cancel()` on it, so every transition pays a full table scan, and so does every worker poll. Adding `(order_id)` and `(due_at)` indexes removed the growth entirely. I did not change the playground's table.

### Memory

About 11 KB per loaded order definition, so 100 versions would cost about 1 MB. One generated definition with 3001 states and 9000 transitions took 2.6 MB.

## What we would have to do

1. **Index the scheduler table** on `order_id` and `due_at` (and on the claim columns used by the lease loop from experiment 3). This is the one change that matters for scale.
2. **Cache parsed definitions when several versions load per request.** Parsing costs about 0.7 ms per version. Cache the built model keyed by the file's hash. Released versions are immutable (experiment 4), so the key never needs invalidating. Cache with APCu, or a serialized file kept in opcache-friendly form. For one version per request this is optional.
3. **Batch the timeout worker** (`claim()` with a limit), as in experiment 3, to bound memory.
4. **Keep definitions modest.** Cost per call grows with transitions. A hundred transitions are fine. Thousands would not be, but nothing in our flows is close.

## Caveats

- Everything ran on SQLite or in memory, in Docker, on one machine. MySQL or Postgres would change the absolute database numbers, though not the shape: an unindexed scan grows with the table, and an indexed lookup doesn't.
- Not measured: real database round-trip time, concurrent load, PHP-FPM with opcache and process reuse, and the cost of service calls inside guards. Those are the likely dominant costs in production.
- The generated definitions are synthetic (three transitions per state, a ring of jumps). They show the growth rate, not a real workflow.
