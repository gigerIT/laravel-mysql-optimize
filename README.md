# MySQL Optimizer

A Laravel package for optimizing MySQL/MariaDB database tables with support for both synchronous and queued execution.

## Why use this package?

MySQL's `OPTIMIZE TABLE` statement reorganizes tables and compacts wasted space, resulting in:

- **Faster queries** through improved data packing and reduced fragmentation
- **Less disk I/O** for full table scans
- **Reduced storage footprint** via better space utilization

Ideal for tables with frequent `INSERT`, `UPDATE`, and `DELETE` operations.

## Requirements

- Laravel 8.x – 12.x (auto-discovered service provider)
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

The package reads the default database to optimize from `config/mysql-optimizer.php`:

```php
<?php

return [
    'database' => env('DB_DATABASE'),
];
```

- Set `DB_DATABASE` in your `.env`, or override `mysql-optimizer.database` at runtime.
- When the `--database=default` option is used, the action resolves to `config('mysql-optimizer.database')`.

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

// Send to a specific queue
OptimizeTablesJob::dispatch('my_database', ['users', 'posts'])
    ->onQueue('database-optimization');

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
        config('database.default'),
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
- Queued runs log start/completion, and per-table results (unless `--no-log` is used).

## Exceptions

- `MySQLOptimizer\Exceptions\DatabaseNotFoundException`
- `MySQLOptimizer\Exceptions\TableNotFoundException`

## Operational notes

- `OPTIMIZE TABLE` may lock tables. Prefer running during low-traffic windows.
- Ensure the DB user has sufficient privileges to run `OPTIMIZE TABLE` and access `INFORMATION_SCHEMA`.

## Testing

```bash
composer test
```

## Compatibility

- Laravel 8.x – 12.x

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
