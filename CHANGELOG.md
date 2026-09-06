# Changelog

All notable changes to this project are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [0.2.0] — 2026-09-06

First release intended for general use. Contains breaking changes from `0.1.x`.

### Breaking

- **`StorageInterface` changed.** `popCommand()` is replaced by `readCommand()`,
  and `acquireLock(string $name, int $ttlSeconds)` is new. Custom storage
  implementations must be updated.
- **Routes register themselves.** If you previously imported
  `@SymfonyHorizonBundle/config/routes.php`, remove that import (or set
  `symfony_horizon.dashboard.enabled: false`) to avoid duplicate routes.
- **`metrics.wait_time_stamp` is renamed to `metrics.wait_time`**, and queue
  wait time now travels as a scalar transport header instead of a Messenger
  stamp. See "Wire format" below.
- **Redis keys are namespaced by schema version** (`horizon:v1:`). Metrics
  recorded by `0.1.x` are not read by `0.2.0`; they expire via their own TTLs.
- **Throughput excludes retries.** The `jobs` metric now counts only work that
  reached a final outcome; retried attempts are counted in a new `retries`
  series. Charts will read lower than on `0.1.x` for apps with retries.

### Wire format

- Queue wait time no longer adds a Messenger stamp to outgoing messages. A stamp
  is serialized as `X-Message-Stamp-<FQCN>`, and Messenger refuses to decode a
  message whose stamp class it cannot load — which made every message produced
  with the bundle undecodable by a consumer without it. The dispatch time is now
  a plain `X-Horizon-Dispatched-At` header, which unaware consumers ignore.

### Added

- Zero-configuration install: transports are auto-discovered, routes register
  themselves, and the bundle ships a Flex recipe.
- `horizon:status` — health check for storage, supervisors, workers and queue
  depth, naming the fix for each failure state.
- `dashboard.read_only` disables retry and delete.
- `php_binary` and `console_path` for FrankenPHP and non-standard layouts.
- Multi-host support: broadcast control commands, per-instance supervisor
  identity, and a cluster lock for `horizon:snapshot`.
- Per-minute `retries` series alongside `jobs` and `failed`.

### Fixed

- A Redis outage can no longer stop message consumption: every storage call from
  the worker is fail-safe, and the record buffer is bounded at 1000 entries.
- `bin/console horizon` crashed under FrankenPHP, where `PHP_BINARY` is empty.
- Job listings reported totals they could not deliver, rendering empty pages.
- Failed-job retry deserialization is restricted to the recorded message class.
- Autoscaling scales down one worker at a time, so the pool no longer churns
  around a `scale_factor` boundary.
- `SIGTERM` is acted on within ~100 ms instead of up to a full tick.
- A single unusable transport DSN no longer takes down the supervisor.

## [0.1.2] — 2026-07-15

- Wipe incompatible legacy Redis keys on first use.

## [0.1.1] — 2026-07-15

- Count only final failures in failed metrics.

## [0.1.0] — 2026-07-15

- Initial release.
