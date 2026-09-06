# Symfony Horizon

Laravel Horizon-style dashboard, supervisor and per-job metrics for **Symfony Messenger**.

- 📊 **Dashboard** — throughput, failed jobs, live workers; self-contained HTML (no build step, no CDN)
- ⏱ **Per-job metrics** — processing time (`hrtime`), **true peak memory per job** (`memory_reset_peak_usage`), retained memory, queue wait time, attempts
- 🚦 **Supervisor** — `bin/console horizon` spawns, monitors and **autoscales** `messenger:consume` workers based on queue depth
- 🔁 **Failed jobs** — full exception + payload, one-click retry to the original transport
- 🏷 **Tags** — `#[HorizonTags('billing')]` attribute or `TaggableInterface`
- 🪶 **Near-zero overhead** — nothing runs in web requests; worker metrics are buffered and flushed to Redis in pipelined batches

## Requirements

- PHP ≥ 8.2 (needed for accurate per-job peak memory)
- Symfony 6.4 / 7.x, symfony/messenger
- Redis for metrics storage (phpredis extension or `predis/predis`) — your Messenger transports can be anything (Doctrine, AMQP, Redis, SQS, ...)

## Installation

```bash
composer require saifulferoz/symfony-horizon
```

That's it. No config file, no route import, no supervisor definitions:

```bash
php bin/console horizon          # starts workers for your transports
php bin/console horizon:status   # is it healthy? what is it doing?
```

Then open **`/horizon`**.

The bundle auto-discovers every consumable Messenger transport (skipping
`sync://` and your failure transport), mounts the dashboard at `/horizon`, and
registers itself via Flex. Everything below is optional tuning.

> **Before production**, protect the dashboard — it can retry and delete jobs:
>
> ```yaml
> # config/packages/security.yaml
> access_control:
>     - { path: ^/horizon, roles: ROLE_ADMIN }
> ```

## Configuration

All keys are optional except `supervisors` (needed for the `horizon` command). Defaults shown:

```yaml
# config/packages/symfony_horizon.yaml
symfony_horizon:
    dashboard:
        enabled: true            # false = register no routes; wire them yourself
        path: /horizon           # where the dashboard is mounted
        read_only: false         # true = observational only; retry/delete return 403

    autodiscover: true           # false = never infer supervisors from transports
    php_binary: ~                # binary used to spawn workers; set to
                                 # 'frankenphp php-cli' under FrankenPHP
    console_path: ~              # console entrypoint (default: bin/console)

    redis:
        dsn: 'redis://localhost:6379'
        # client_service: my.redis.service   # reuse an existing \Redis / Predis client instead
        prefix: 'horizon:'

    metrics:
        flush_batch: 25          # buffer N job records per Redis pipeline flush
        flush_interval: 3        # ...or flush at least every N seconds
        sampling: 1.0            # store 1.0 = all job records; counters always count everything
        capture_payload: false   # store (truncated) payloads of successful jobs
        payload_max_bytes: 10240
        wait_time: true          # measure queue wait via an X-Horizon-Dispatched-At header

    trim:                        # retention, minutes
        recent: 60               # completed job records
        failed: 10080            # failed job records (7 days)
        metrics: 1440            # per-minute metric buckets (24 h)
        snapshots: 10080         # hourly snapshots (7 days)

    # Omit `supervisors` entirely to auto-discover transports. Define it to
    # take full control — auto-discovery then switches off.
    supervisors:
        default:
            transports: [async]
            min_processes: 1
            max_processes: 10
            balance: auto        # off = pin to min_processes, auto = scale on queue depth
            scale_factor: 10     # pending messages one worker is expected to absorb
            autoscale_cooldown: 3
            memory_limit: 128    # MB, passed to messenger:consume --memory-limit
            time_limit: 3600     # s, passed to messenger:consume --time-limit
            consume_options: []  # extra CLI options, e.g. ['--queues=high']
```

## Running

```bash
bin/console horizon             # start supervisor + workers (use systemd/supervisord in prod)
bin/console horizon:status      # health check: storage, supervisors, workers, queue depth
bin/console horizon:pause       # stop consuming (graceful)
bin/console horizon:continue    # resume
bin/console horizon:terminate   # graceful shutdown
bin/console horizon:snapshot    # roll metrics into snapshots — cron it every 5 minutes
```

Open `https://your-app/horizon` for the dashboard. Autoscaling uses the transports' message counts and works with any transport implementing `MessageCountAwareInterface` (Doctrine, Redis, AMQP, ...).

You can also run plain `bin/console messenger:consume` workers without the supervisor — they still report metrics and appear on the dashboard.

### FrankenPHP / Caddy `supervisor`

If Caddy's `supervisor` directive already manages your workers, **do not run
`bin/console horizon`** — you would end up with two process managers. Keep your
Caddyfile as-is; workers report metrics either way, because collection hooks
Messenger's worker events, not the supervisor:

```caddyfile
supervisor {
    php /app/bin/console messenger:consume default --time-limit=3600 --memory-limit=512M -vv {
        dir /app
        redirect_stdout stdout
        redirect_stderr stderr
        restart_policy always
    }
}
```

The dashboard, per-job metrics, failed jobs and retry all work. You lose only
what the Horizon supervisor itself provides: autoscaling on queue depth and
`horizon:pause` / `horizon:continue` (Caddy owns the process lifecycle instead).
Supervisor rows on the dashboard stay empty; the Workers list is populated.

If you *do* want Horizon to supervise under FrankenPHP, point it at the CLI
binary — under FrankenPHP `PHP_BINARY` is empty, so it must be set explicitly:

```yaml
symfony_horizon:
    php_binary: 'frankenphp php-cli'
```

and run `frankenphp php-cli bin/console horizon` as the supervised command.

### Deployment note

Restart Horizon on deploy (`horizon:terminate` + process manager restart), exactly like Laravel Horizon. Example systemd unit:

```ini
[Service]
ExecStart=/usr/bin/php /srv/app/bin/console horizon
ExecStop=/usr/bin/php /srv/app/bin/console horizon:terminate
Restart=always
```

## Tagging jobs

```php
use Saifulferoz\SymfonyHorizon\Tags\HorizonTags;
use Saifulferoz\SymfonyHorizon\Tags\TaggableInterface;

#[HorizonTags('billing', 'critical')]
final class ChargeInvoice implements TaggableInterface
{
    public function __construct(public int $invoiceId) {}

    public function horizonTags(): array
    {
        return ['invoice:' . $this->invoiceId];
    }
}
```

## Resilience

Monitoring must never break the thing it monitors:

- **A storage outage cannot stop consumption.** Every Redis call from the worker
  is fail-safe — a failure is logged (channel `horizon`) and the metrics batch is
  dropped, while message handling continues untouched.
- **The buffer is bounded.** At most 1000 records are held in memory, so a
  prolonged Redis outage can never exhaust a worker's memory.
- **Keys are namespaced by schema version** (`horizon:v1:`). An upgrade that
  changes the key layout simply starts writing under a new namespace; old keys
  expire through their own TTLs. No blocking `KEYS` scan, no cross-process wipe
  race, and no destroyed data.
- **Job indexes self-heal.** Ids whose payload has expired are pruned on read, so
  the counts in the UI always match what can actually be listed.

## The bundle stays out of your wire format

Queue wait time is measured with a plain `X-Horizon-Dispatched-At` transport
header, never a Messenger stamp. A stamp is serialized as
`X-Message-Stamp-<FQCN>`, and Messenger **refuses to decode a message whose
stamp class it cannot load** — so a stamp would make every message produced by
your app undecodable by any consumer that does not have this bundle installed
(a second service on the same transport, or a partial rollout).

A scalar header is ignored by consumers that know nothing about it, so
installing or removing the bundle never poisons a queue. Set
`metrics.wait_time: false` to drop the header entirely.

## Running on multiple hosts

The bundle is safe to run on several servers against one Redis:

- **Control commands are broadcast, not queued.** `horizon:pause`, `horizon:continue`
  and `horizon:terminate` write a versioned control state that *every* instance
  reads, so a pause stops the whole fleet rather than whichever process polled
  first. An instance that starts while the fleet is paused pauses itself; one
  that starts long after a terminate does not replay it.
- **Supervisors are identified per instance** (`name@host:pid`), so each host
  appears as its own row on the dashboard with its own process count instead of
  overwriting the others.
- **`horizon:snapshot` is cluster-safe.** Cron can fire on every host; a
  short-lived Redis lock lets only one write the snapshot for an interval. The
  lock self-expires, so a crashed run cannot block the next one. Use
  `--force` to bypass it deliberately.

## Why it doesn't slow your app down

1. **No web/dispatch overhead.** Metrics hook exclusively into `Worker*` events, which only exist inside `messenger:consume`. The one dispatch-side cost is a single timestamp header written during serialization (microseconds, disable with `wait_time: false`).
2. **Batched pipelined writes.** Job records and counters are buffered in the worker and written in one Redis pipeline per batch (default 25 jobs / 3 s) — not 3–5 round-trips per message.
3. **Bounded Redis usage.** Charts read O(1) per-minute aggregate buckets; individual records live in trimmed, TTL'd keys.
4. **Payloads are opt-in** and truncated; failed jobs always keep enough to debug and retry.
5. **Sampling** (`sampling: 0.1`) for very high-volume queues — counters stay exact, only per-job detail rows are sampled.

## Contributing

```bash
composer install
composer test        # PHPUnit
composer phpstan     # static analysis (level 6)
composer cs          # coding standards (dry run)
composer ci          # everything CI runs
```

## License

MIT
