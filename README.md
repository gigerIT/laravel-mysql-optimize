# MySQL Optimizer

A Laravel package for optimizing MySQL/MariaDB database tables with support for both synchronous and queued execution.

## Why use this package?

MySQL's `OPTIMIZE TABLE` statement reorganizes tables and compacts wasted space, resulting in:

- **Faster queries** through improved data packing and reduced fragmentation
- **Less disk I/O** for full table scans
- **Reduced storage footprint** via better space utilization

Ideal for tables with frequent `INSERT`, `UPDATE`, and `DELETE` operations.

## Requirements

- Laravel 8.x – 13.x (auto-discovered service provider)
- MySQL 5.7+/8.0+ or MariaDB (uses INFORMATION_SCHEMA and OPTIMIZE TABLE)

## Installation

```bash
composer require gigerit/laravel-mysql-optimizer
```

Publish the configuration (optional):

```bash
php artisan vendor:publish --provider="MySQLOptimizer\ServiceProvider"
```

## Configuration

The published `config/mysql-optimizer.php` contains the database and queue defaults:

```php
<?php

return [
    'database' => env('DB_DATABASE'),

    'queue' => [
        'per_table' => (bool) env('MYSQL_OPTIMIZER_PER_TABLE', false),
        'connection' => env('MYSQL_OPTIMIZER_QUEUE_CONNECTION'),
        'name' => env('MYSQL_OPTIMIZER_QUEUE'),
        'timeout' => (int) env('MYSQL_OPTIMIZER_TIMEOUT', 3600),
        'tries' => (int) env('MYSQL_OPTIMIZER_TRIES', 1),
        'backoff' => (int) env('MYSQL_OPTIMIZER_BACKOFF', 3600),
        'unique_for' => (int) env('MYSQL_OPTIMIZER_UNIQUE_FOR', 0),
    ],
];
```

- Set `DB_DATABASE` in your `.env`, or override `mysql-optimizer.database` at runtime.
- When the `--database=default` option is used, the action resolves to `config('mysql-optimizer.database')`.
- `queue.connection` is a Laravel queue connection key, such as `redis-optimizer`. `null` inherits `queue.default`.
- `queue.name` is the queue name within that connection. `null` inherits the connection's default queue.
- `queue.timeout`, `queue.tries`, and `queue.backoff` configure the queued job runtime. In per-table mode, the parent orchestrator always uses one attempt; child jobs use the configured values.
- `queue.unique_for` configures Laravel's unique-dispatch lock. `0` uses cache-store-specific lock duration behavior. For example, Redis creates a lock without a TTL, while Laravel's database cache lock uses its store default timeout. Laravel normally releases the lock after successful processing or final failure. An abruptly killed worker can leave a stale lock when the store has no suitable expiry; after confirming no optimizer job or DDL is still running, remove only the affected lock using that cache backend's tooling. A finite value avoids an indefinite stale lock, but it must cover queue wait time plus job timeout plus at least 10 seconds.
- `queue.per_table=false` keeps the default sequential behavior. See [Per-table mode](#per-table-mode) before opting in.

## CLI usage

```bash
php artisan db:optimize [--database=default] [--table=*] [--queued] [--no-log]
```

Options:

- `--database=default`: Database name to optimize. Use `default` to use `config('mysql-optimizer.database')`.
- `--table=*`: Repeatable. If omitted, all tables in the target database are optimized.
- `--queued`: Queue the optimization as a job instead of running synchronously.
- `--no-log`: Disable job logging; only applies when `--queued` is used.

### Examples

Optimize all tables in the default database:

```bash
php artisan db:optimize
```

Optimize specific tables:

```bash
php artisan db:optimize --table=users --table=posts
```

Optimize a specific database:

```bash
php artisan db:optimize --database=my_database
```

Queue optimization for all tables:

```bash
php artisan db:optimize --queued
```

Queue optimization for selected tables with logging disabled:

```bash
php artisan db:optimize --table=users --table=posts --queued --no-log
```

## Using the Job directly

```php
use MySQLOptimizer\Jobs\OptimizeTablesJob;

// Queue optimization for specific tables (logging enabled by default)
OptimizeTablesJob::dispatch('my_database', ['users', 'posts']);

// Override both the Laravel queue connection and queue name
OptimizeTablesJob::dispatch('my_database', ['users', 'posts'])
    ->onConnection('redis-optimizer')
    ->onQueue('mysql-optimizer');

// Delay execution
OptimizeTablesJob::dispatch('my_database', ['users', 'posts'])
    ->delay(now()->addMinutes(5));

// Disable logging explicitly
OptimizeTablesJob::dispatch('my_database', ['users', 'posts'], false);
```

When using queued execution, ensure a worker is running:

```bash
php artisan queue:work
```

The `db:optimize --queued` command validates package configuration before dispatch. Direct dispatch and scheduled job objects cannot be fully validated before fluent routing such as `onConnection()` is applied, so they validate their actual connection when `handle()` begins. Execution-time validation occurs before optimizer start, progress, or completion logs and before `OPTIMIZE TABLE` statements. Laravel may still invoke the job's `failed()` hook and emit a permanent-failure log for the rejected job.

## Queue safety

Long-running `OPTIMIZE TABLE` work should use a dedicated queue connection and one worker. Redis moves an unacknowledged reserved job back to the ready queue when `retry_after` expires. Another worker can then execute the same payload while the first worker is still running.

For Redis, database, and Beanstalkd queue drivers, this package rejects a connection unless:

```text
job timeout + 10 seconds <= queue retry_after
```

If an inspectable built-in connection omits `retry_after`, validation uses Laravel's 60-second fallback. With the default 3600-second job timeout, that connection is rejected because it does not provide the required 10-second margin.

When Horizon processes the queue, use the stricter ordering:

```text
job timeout < Horizon supervisor timeout < queue retry_after
```

For example, configure a dedicated Laravel queue connection in `config/queue.php`. Here `redis-optimizer` is the Laravel queue connection key, while its nested `connection` value `default` is the Redis client connection:

```php
'connections' => [
    // ...

    'redis-optimizer' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => 'mysql-optimizer',
        'retry_after' => 5600,
    ],
],
```

Route optimizer jobs and set their timeout in `.env`:

```dotenv
MYSQL_OPTIMIZER_QUEUE_CONNECTION=redis-optimizer
MYSQL_OPTIMIZER_QUEUE=mysql-optimizer
MYSQL_OPTIMIZER_TIMEOUT=5400
MYSQL_OPTIMIZER_TRIES=1
```

Then assign the queue to a dedicated Horizon supervisor in `config/horizon.php`:

```php
'supervisor-optimizer' => [
    'connection' => 'redis-optimizer',
    'queue' => ['mysql-optimizer'],
    'balance' => false,
    'maxProcesses' => 1,
    'tries' => 1,
    'timeout' => 5500,
],
```

The package can validate configured timeout and `retry_after` values, but it cannot inspect Horizon's effective supervisor timeout or worker count. Keep `maxProcesses` at `1`: concurrent whole-database or per-table DDL increases lock and I/O risk without making one database optimize run safer.

`backoff` only delays a retry after an unhandled exception. An explicit `release($delay)` uses its supplied delay instead. Neither mechanism extends a Redis reservation or prevents another worker from claiming an expired payload. `ShouldBeUnique` prevents duplicate dispatches while its cache lock exists; it does not prevent the same reserved payload from being migrated and reserved again. See [issue #4](https://github.com/gigerIT/laravel-mysql-optimize/issues/4) for the original failure mode.

The package cannot inspect external visibility timeouts used by SQS or custom queue drivers. Configure those systems so their visibility timeout safely exceeds the job and worker timeouts. Laravel job timeouts also require PCNTL, and blocking PDO/database calls may not be interrupted promptly. The database server can continue DDL after the worker process times out, so use conservative margins and database-side monitoring.

## Per-table mode

Default queued execution remains monolithic and sequential in shape: one `OptimizeTablesJob` resolves the target tables and optimizes all of them in the same reserved job. Safety behavior does change: defaults are now `tries=1` and `unique_for=0`, unsafe inspectable queue timing is rejected, and each `OPTIMIZE TABLE` statement uses the resolved, fully qualified database and table names.

Per-table mode is opt-in:

```dotenv
MYSQL_OPTIMIZER_PER_TABLE=true
MYSQL_OPTIMIZER_QUEUE_CONNECTION=redis-optimizer
MYSQL_OPTIMIZER_QUEUE=mysql-optimizer
```

When enabled, `OptimizeTablesJob` becomes a one-attempt orchestrator. It resolves canonical database and table names from `INFORMATION_SCHEMA`, then dispatches one unique `OptimizeTableJob` for each resolved database/table pair. Each child optimizes exactly one table, so an ordinary exception retry does not replay tables completed by other child jobs.

Per-table mode requires an explicit, non-empty package queue connection and queue name. The package rejects inherited routing in this mode. Use the dedicated `redis-optimizer` / `mysql-optimizer` route and one-worker Horizon supervisor shown above. The package cannot verify that only one worker consumes the queue.

Important per-table limits:

- Parent orchestration always uses `tries=1`. Child attempts, timeout, and backoff use package queue configuration.
- Dispatch is not transactional. If parent fails after dispatching some children, it does not retry automatically or persist aggregate progress.
- Logs identify a shared run ID and report child outcomes independently. Parent's "jobs dispatched" log is not whole-run completion.
- A timed-out child has ambiguous state: its server-side `OPTIMIZE TABLE` may continue after worker termination. Retrying can therefore overlap database work even when one queue worker is configured.
- Unique locks require a shared cache accessible by all dispatchers and workers. Canonical database/table names form child uniqueness keys.
- After unique locks release, a later scheduled or manual run can optimize tables completed by an earlier partial run again.

## Scheduling

Optimize all tables weekly on Sunday at 02:00 as a queued job:

```php
use Illuminate\Console\Scheduling\Schedule;
use MySQLOptimizer\Jobs\OptimizeTablesJob;

protected function schedule(Schedule $schedule)
{
    $schedule->job(new OptimizeTablesJob())
        ->weekly()
        ->sundays()
        ->at('02:00');
}
```

Optimize selected high-traffic tables daily at 03:00 as a queued job:

```php
use Illuminate\Console\Scheduling\Schedule;
use MySQLOptimizer\Jobs\OptimizeTablesJob;

protected function schedule(Schedule $schedule)
{
    $schedule->job(new OptimizeTablesJob(
        config('mysql-optimizer.database'),
        ['users', 'orders', 'products']
    ))->daily()->at('03:00');
}
```

Or schedule the console command to run synchronously:

```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('db:optimize')
        ->weekly()
        ->sundays()
        ->at('02:00');
}
```

## Behavior and logging

- Synchronous runs show a progress bar and success counts in the console.
- Default queued runs log start/completion and per-table results (unless `--no-log` is used).
- Per-table queued runs log parent dispatch and each child result with a shared run ID; no aggregate completion state is persisted.

## Exceptions

- `MySQLOptimizer\Exceptions\DatabaseNotFoundException`
- `MySQLOptimizer\Exceptions\InvalidQueueConfigurationException`
- `MySQLOptimizer\Exceptions\TableNotFoundException`

## Operational notes

- `OPTIMIZE TABLE` may lock tables. Prefer running during low-traffic windows.
- Ensure the DB user has sufficient privileges to run `OPTIMIZE TABLE` and access `INFORMATION_SCHEMA`.

## Testing

```bash
composer test
```

## Compatibility

- Laravel 8.x – 13.x

## Contributing

We welcome contributions! Please see:

- [Contributing Guidelines](CONTRIBUTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)

## Standards

This package follows:

- [PSR-4 Autoloading](https://www.php-fig.org/psr/psr-4/)
- [PSR-12 Coding Style](https://www.php-fig.org/psr/psr-12/)

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## Credits

Updated, Extended & Maintained by [gigerIT](https://github.com/gigerIT)

Original idea for Laravel 8 by [Zak Rahman](https://github.com/zakriyarahman)

---

💡 Pro tip: schedule regular optimizations using Laravel's task scheduler for automated maintenance.
