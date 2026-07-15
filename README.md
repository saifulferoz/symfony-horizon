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

Register the bundle (if not done by Flex), in `config/bundles.php`:

```php
Saifulferoz\SymfonyHorizon\SymfonyHorizonBundle::class => ['all' => true],
```

Import the routes, e.g. `config/routes/symfony_horizon.yaml`:

```yaml
symfony_horizon:
    resource: '@SymfonyHorizonBundle/config/routes.php'
    prefix: /horizon
```

**Protect the dashboard** with your own security config — the bundle deliberately ships no auth:

```yaml
# config/packages/security.yaml
access_control:
    - { path: ^/horizon, roles: ROLE_ADMIN }
```

## Configuration

All keys are optional except `supervisors` (needed for the `horizon` command). Defaults shown:

```yaml
# config/packages/symfony_horizon.yaml
symfony_horizon:
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
        wait_time_stamp: true    # tiny dispatch-side stamp enabling queue wait-time metrics

    trim:                        # retention, minutes
        recent: 60               # completed job records
        failed: 10080            # failed job records (7 days)
        metrics: 1440            # per-minute metric buckets (24 h)
        snapshots: 10080         # hourly snapshots (7 days)

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
bin/console horizon:pause       # stop consuming (graceful)
bin/console horizon:continue    # resume
bin/console horizon:terminate   # graceful shutdown
bin/console horizon:snapshot    # roll metrics into snapshots — cron it every 5 minutes
```

Open `https://your-app/horizon` for the dashboard. Autoscaling uses the transports' message counts and works with any transport implementing `MessageCountAwareInterface` (Doctrine, Redis, AMQP, ...).

You can also run plain `bin/console messenger:consume` workers without the supervisor — they still report metrics and appear on the dashboard.

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

## Why it doesn't slow your app down

1. **No web/dispatch overhead.** Metrics hook exclusively into `Worker*` events, which only exist inside `messenger:consume`. The single optional dispatch-side hook adds one timestamp stamp (microseconds, disable with `wait_time_stamp: false`).
2. **Batched pipelined writes.** Job records and counters are buffered in the worker and written in one Redis pipeline per batch (default 25 jobs / 3 s) — not 3–5 round-trips per message.
3. **Bounded Redis usage.** Charts read O(1) per-minute aggregate buckets; individual records live in trimmed, TTL'd keys.
4. **Payloads are opt-in** and truncated; failed jobs always keep enough to debug and retry.
5. **Sampling** (`sampling: 0.1`) for very high-volume queues — counters stay exact, only per-job detail rows are sampled.

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

MIT
