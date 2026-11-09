# Plan: Mercure real-time dashboard updates (with polling fallback)

## Context

The dashboard currently polls `GET /horizon/api/stats` (plus the active view's
endpoint) every 3 seconds from `setInterval`. That is simple and dependency-free,
but it has real costs:

- **Latency floor.** A job that fails is invisible for up to 3 s.
- **Wasted load.** Every open tab issues ~1,200 requests/hour whether or not
  anything changed. Each `stats` call does ~61 Redis reads (60 minute-buckets +
  counters), so 10 idle tabs ≈ 200k Redis reads/hour for no new information.
- **It scales with viewers, not with events** — exactly backwards.

Mercure (SSE) inverts this: the server pushes when something actually changes.

**Goal:** add Mercure as an opt-in transport, keep polling as the default, and
make the two interchangeable behind one config switch. Nobody who installs the
bundle today should notice a difference until they opt in.

## Constraints discovered in the codebase

These shape the design and are not negotiable:

1. **No Twig dependency.** `composer.json` requires only php, psr/log, console,
   framework-bundle, messenger, process. The dashboard is plain HTML with
   `__BASE_PATH__` substituted by `str_replace` in
   [DashboardController](../../src/Controller/DashboardController.php). So the
   documented `mercure()` Twig helper is unavailable — the hub URL and
   subscriber token must reach the page another way (a second placeholder).
2. **Mercure must stay optional.** `symfony/mercure-bundle` goes in `suggest`
   + `require-dev`, never `require`. The bundle must boot, and the dashboard
   must work, with Mercure absent.
3. **The worker hot path is sacred.** `MetricsCollector` is deliberately
   fail-safe and batched; metrics collection must never slow or break message
   consumption. **Workers must not publish to Mercure** (see below).
4. **Multi-host.** Several `horizon` supervisors and many workers share one
   Redis. Publishing must not produce N duplicate updates per tick.

## Key design decision: who publishes?

Three candidate publish points, and why I picked the third:

| Option | Verdict |
|---|---|
| **A. Workers** publish on every flush | ✗ Rejected. Adds a synchronous HTTP call to the hot path, violating the bundle's core promise. With 10 workers × 25-job batches you get a thundering herd of near-identical updates. |
| **B. API controller** publishes when polled | ✗ Nonsense — it would still require polling to trigger it. |
| **C. The `horizon` supervisor** publishes once per tick | ✓ **Chosen.** It already runs a 1 s loop (`HorizonCommand::sleepUntilNextTick`), already reads storage for heartbeats, is long-lived, and is off the message-handling path entirely. |

**The multi-host problem with option C:** every supervisor instance would publish
the same snapshot each tick. Solved by reusing the existing
`StorageInterface::acquireLock()` (added for `horizon:snapshot`): whichever
instance wins a short-TTL lock publishes for the cluster. No new primitive.

**Consequence worth stating plainly:** real-time updates require a running
`bin/console horizon`. Users on Caddy's `supervisor` directive (documented in the
README) have no Horizon supervisor process, so **Mercure mode will not push for
them** — they must stay on polling, or run a lightweight publisher. I'll add a
dedicated `horizon:broadcast` command for exactly that case, and `horizon:status`
will say which mode is active and whether a publisher is alive.

## Architecture

```
                        ┌──────────────────────────────────┐
  workers ──flush──►    │            Redis                 │
  supervisor ─hb──►     └──────────────┬───────────────────┘
                                       │ read snapshot (1/s, lock-guarded)
                        ┌──────────────▼───────────────────┐
                        │  DashboardBroadcaster            │
                        │  (in horizon tick / broadcast cmd)│
                        └──────────────┬───────────────────┘
                                       │ publish (private)
                                ┌──────▼───────┐
                                │ Mercure Hub  │
                                └──────┬───────┘
                                       │ SSE
                    dashboard ◄────────┘   (falls back to polling on error)
```

### New files

```
src/Realtime/
  UpdatePublisherInterface.php   # publish(string $topic, array $payload): void
  MercureUpdatePublisher.php     # wraps HubInterface; private updates
  NullUpdatePublisher.php        # default; does nothing
  DashboardBroadcaster.php       # builds the snapshot, lock-guards, publishes
  SnapshotBuilder.php            # extracted from ApiController (shared)
src/Command/
  HorizonBroadcastCommand.php    # standalone publisher loop (Caddy/no-supervisor)
```

`UpdatePublisherInterface` is the seam: Mercure is one implementation, and the
bundle never type-hints `HubInterface` outside `MercureUpdatePublisher`, so the
hard dependency stays contained and testable.

### Payload and topics

One topic for the dashboard overview, one per detail view, so a viewer on the
Workers tab isn't woken by metrics churn:

- `horizon://dashboard/stats` — the `stats` payload (already shaped by `ApiController::stats`)
- `horizon://dashboard/workers`
- `horizon://dashboard/jobs` — fired when a job fails or is retried/deleted

Updates are published **private** (`new Update($topic, $json, private: true)`),
so only subscribers whose JWT carries a matching `mercure.subscribe` claim get
them. This matters: the payload contains queue names, message class names, and
failure counts — operational detail that should not be world-readable on a hub
shared with other apps.

### Reuse, not duplication

`ApiController::stats()` already builds exactly the snapshot we want to push.
Extract that into `SnapshotBuilder` and have **both** the controller and the
broadcaster call it. This guarantees polling and Mercure deliver byte-identical
payloads — otherwise the two modes drift and the dashboard subtly misbehaves in
one of them.

## Configuration

```yaml
symfony_horizon:
    dashboard:
        realtime:
            transport: polling        # polling | mercure
            interval: 3               # seconds; polling cadence AND Mercure
                                      # heartbeat/fallback poll
            mercure:
                hub: ~                # service id; defaults to mercure.hub.default
                topic_prefix: 'horizon://dashboard/'
                publish_interval: 1   # seconds between pushes (supervisor tick)
```

- `transport: polling` (default) — today's behaviour, byte-for-byte.
- `transport: mercure` — the page opens an `EventSource`; polling is disabled
  while the stream is healthy.

**Validation at compile time, not runtime:** if `transport: mercure` but
`symfony/mercure-bundle` isn't installed or the hub service is missing, throw a
`LogicException` from the extension with the exact `composer require` line.
Failing at container build is far kinder than a dashboard that silently never
updates.

## Client behaviour (the part that decides whether this feels good)

The dashboard gets a second placeholder, `__REALTIME__`, holding a small JSON
config block (transport, hub URL, topics, interval). Current JS calls
`setInterval(refresh, 3000)` unconditionally; that becomes:

```js
if (RT.transport === "mercure" && "EventSource" in window) startStream();
else startPolling();
```

Non-negotiable client rules — these are what separate a working demo from
something you'd leave running on a wall display:

1. **Always fetch once on load.** SSE delivers *changes*; the first paint must
   not wait for the next event.
2. **Fall back automatically.** On `EventSource.onerror` with
   `readyState === CLOSED`, start polling and show "realtime unavailable — polling"
   in the status chip. Retry the stream with backoff. A dashboard that silently
   stops updating is worse than one that polls.
3. **Degrade on stale stream.** If no message arrives within `3 × publish_interval`,
   treat the stream as dead even without an error (hubs behind some proxies hang
   rather than error).
4. **Close on `visibilitychange`/unload**, mirroring the existing polling pause,
   so background tabs don't hold hub connections.
5. **Keep detail views on HTTP.** Job detail, retry and delete stay request/response.
   Only the live-updating surfaces (stats, workers, failed count) move to push.

## Security

The dashboard already ships no auth and documents `access_control`. Mercure adds
a second channel that bypasses Symfony's firewall entirely, so:

- Updates are **private**; the subscriber JWT is minted per-request by the
  dashboard controller (which *is* behind the host app's firewall) and set as the
  `mercure` cookie, scoped to the horizon topics only — never `*`.
- Document that `MERCURE_JWT_SECRET` must not be shared with untrusted apps, and
  that `read_only: true` does not restrict what the stream exposes.
- Note the cookie's same-site/domain requirement (app and hub must share a
  registrable domain) — the most common reason a Mercure setup silently fails.

## Implementation steps

1. **Extract `SnapshotBuilder`** from `ApiController::stats()`; controller
   delegates. Pure refactor, no behaviour change — existing `ApiControllerTest`
   must stay green untouched.
2. **Publisher seam**: `UpdatePublisherInterface` + `NullUpdatePublisher`, wired
   as default. No Mercure yet.
3. **`MercureUpdatePublisher`** + compile-time validation + config tree.
4. **`DashboardBroadcaster`** (lock-guarded), called from `Supervisor::tick()`;
   plus `horizon:broadcast` for supervisor-less deployments.
5. **Client**: `__REALTIME__` placeholder, `startStream()`/`startPolling()`,
   fallback + staleness detection + status-chip states.
6. **`horizon:status`**: report transport, hub reachability, last publish time.
7. **Docs**: README section covering both modes, the Caddy caveat, and the
   cookie/domain gotcha; CHANGELOG entry (additive, non-breaking).

## Testing

- **Unit:** `DashboardBroadcaster` publishes once when it holds the lock and not
  at all when it doesn't (reuse `SpyStorage::$lockGranted`); `NullUpdatePublisher`
  is a no-op; a spy publisher captures topic + payload shape.
- **Parity (the important one):** assert `SnapshotBuilder` output is identical
  whether read through the API or the broadcaster — this is what stops the two
  transports drifting.
- **Config:** `transport: mercure` without the bundle throws at compile time;
  `polling` default produces `NullUpdatePublisher`.
- **E2E (manual, scripted):** real Mercure hub in Docker + the scratch Symfony
  app already used this session; assert a failed job appears in the browser in
  < 1 s, then kill the hub and confirm the dashboard falls back to polling and
  says so.

## Scope and risks

**Explicitly out of scope:** replacing the JSON API (it stays, and is the
fallback), pushing job-detail payloads, and any change to default behaviour.

**Main risk:** Mercure setups fail for environmental reasons (cookie domain, hub
URL, JWT secret mismatch) far more often than code reasons. Mitigated by
compile-time validation, `horizon:status` diagnostics, and a client that degrades
loudly rather than silently.
