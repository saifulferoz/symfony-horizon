# Symfony Horizon

A Laravel Horizon style dashboard and queue worker supervisor for **Symfony Messenger**.

Symfony Horizon provides a beautiful, real-time glassmorphism dashboard and code-driven configuration for your Symfony Messenger transports. It monitors key metrics such as job throughput, runtimes, queue backlogs, and allows you to inspect, retry, or delete failed jobs directly from the web panel.

---

## Features

- 📊 **Real-time Metrics Dashboard**: View throughput curves, queue status, active workers, and failure logs.
- ⚙️ **Code-Driven Configuration**: Define your supervisors and queue workers in your Symfony YAML configuration.
- 📈 **Dynamic Auto-Scaling**: Automatically scale worker processes up or down based on transport queue backlogs.
- 🛡️ **Built-in Security**: Secure your dashboard with default roles (e.g. `ROLE_ADMIN`) or custom security voters.
- 🔄 **Failed Jobs Manager**: Inspect stack traces, retry/re-queue failed messages, or delete them permanently.
- 🚀 **Zero Front-end Build Dependencies**: Serving a self-contained, high-performance HTML dashboard out-of-the-box.

---

## Installation

Add the bundle to your project's `composer.json` or install it using Composer:

```bash
composer require saifulferoz/symfony-horizon
```

Register the bundle in `config/bundles.php` (if not done automatically by Symfony Flex):

```php
return [
    // ...
    Saifulferoz\SymfonyHorizon\SymfonyHorizonBundle::class => ['all' => true],
];
```

---

## Configuration

Configure the bundle by creating a new `config/packages/symfony_horizon.yaml` file:

```yaml
symfony_horizon:
    prefix: 'horizon:'                  # Redis key prefix
    failure_transport: 'failed'         # Your failure transport receiver name
    storage:
        type: 'redis'
        redis_connection: 'snc_redis.default' # Redis client service ID
    dashboard:
        path: '/horizon'
        role: 'ROLE_ADMIN'              # Required role to access dashboard
    supervisors:
        default-supervisor:
            connection: 'async'         # Messenger transport name
            queues: ['default', 'emails'] # Specific queues to consume
            processes: 3                # Min worker processes (always running)
            max_processes: 10           # Max worker processes for auto-scaling
            balance: 'auto'             # auto, simple, or false
            memory_limit: 128           # MB limit per worker before exit
            time_limit: 3600            # Worker TTL in seconds before exit
            sleep: 3                    # Seconds to sleep when queues are empty
```

Import the routes in `config/routes.yaml`:

```yaml
symfony_horizon:
    resource: '@SymfonyHorizonBundle/Controller/'
    type: attribute
```

---

## Usage

### Starting the Daemon

Run the master daemon using the console command. The daemon will read your configuration, spawn the required supervisor pools, and manage the workers:

```bash
bin/console messenger:horizon
```

It is highly recommended to run this command under a system-level process manager like **Supervisor** or **Systemd** in production to ensure it remains active:

```ini
[program:symfony-horizon]
process_name=%(program_name)s
command=php /path-to-your-project/bin/console messenger:horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/path-to-your-project/var/log/horizon.log
```

---

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.
