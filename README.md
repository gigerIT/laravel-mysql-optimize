# MySQL Optimizer

A Laravel package for optimizing MySQL database tables with support for both synchronous and asynchronous execution.

## Why Use This Package?

MySQL's `OPTIMIZE TABLE` statement reorganizes tables and compacts wasted space, resulting in:

- **Faster queries** through improved data packing and reduced fragmentation
- **Less disk I/O** for full table scans
- **Reduced storage footprint** through better space utilization

Perfect for tables with frequent `INSERT`, `UPDATE`, and `DELETE` operations.

## Installation

Add the service provider to your `config/app.php`:

```php
'providers' => [
    // ...
    MySQLOptimizer\ServiceProvider::class,
];
```

## Usage

### Console Commands

#### Synchronous Optimization
```bash
# Optimize all tables in the default database
php artisan db:optimize

# Optimize specific tables
php artisan db:optimize --table=users --table=posts

# Optimize specific database
php artisan db:optimize --database=my_database
```

#### Asynchronous Optimization (Queued)
```bash
# Queue optimization for all tables
php artisan db:optimize --queued

# Queue optimization for specific tables
php artisan db:optimize --table=users --table=posts --queued

# Queue optimization with logging disabled
php artisan db:optimize --queued --no-log
```

### Using the Action Class Directly

```php
use MySQLOptimizer\Actions\OptimizeTablesAction;
use Illuminate\Database\Query\Builder;

// Create the action
$builder = app(Builder::class);
$action = new OptimizeTablesAction($builder);

// Get table count before optimization
$count = $action->getTableCount('my_database', ['users', 'posts']);

// Execute optimization with progress callback
$results = $action->execute(
    'my_database',
    ['users', 'posts'],
    function ($table, $success) {
        echo "Optimizing {$table}: " . ($success ? 'SUCCESS' : 'FAILED') . "\n";
    }
);

// Process results
foreach ($results as $result) {
    echo "Table: {$result['table']}, Success: " . ($result['success'] ? 'Yes' : 'No') . "\n";
}
```

### Using the Job Directly

```php
use MySQLOptimizer\Jobs\OptimizeTablesJob;

// Dispatch optimization job
OptimizeTablesJob::dispatch('my_database', ['users', 'posts'], true);

// Dispatch to specific queue
OptimizeTablesJob::dispatch('my_database', ['users', 'posts'], true)
    ->onQueue('database-optimization');

// Dispatch with delay
OptimizeTablesJob::dispatch('my_database', ['users', 'posts'], true)
    ->delay(now()->addMinutes(5));
```

## Configuration

Create a config file `config/mysql-optimizer.php`:

```php
<?php

return [
    'database' => env('DB_DATABASE', 'mysql'),
];
```

## Features

- **Action-based architecture**: Reusable optimization logic
- **Progress tracking**: Real-time progress updates during optimization
- **Queueable jobs**: Background processing for large datasets
- **Flexible table selection**: Optimize all tables or specific ones
- **Database validation**: Ensures databases and tables exist before optimization
- **Comprehensive logging**: Track optimization results and failures
- **Error handling**: Graceful handling of optimization failures

## Exception Handling

The package throws specific exceptions for invalid arguments:

- `MySQLOptimizer\Exceptions\DatabaseNotFoundException`
- `MySQLOptimizer\Exceptions\TableNotFoundException`

## Performance Notes

- First optimization after bulk data changes is typically slower
- Benefits vary by table structure and data patterns
- Large tables may require significant time to optimize
- Consider running during low-traffic periods

## Testing

```bash
composer test
```

## Contributing

We welcome contributions! Please see:

- [Contributing Guidelines](CONTRIBUTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)

## Standards

This package follows:

- [PSR-4 Autoloading](https://www.php-fig.org/psr/psr-4/)
- [PSR-2 Coding Style](https://www.php-fig.org/psr/psr-2/)
- [PSR-1 Basic Coding Standard](https://www.php-fig.org/psr/psr-1/)

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## Credits

Updated, Extenden & Maintained by [gigerIT](https://github.com/gigerIT)
Original for Laravel 8 Created by [Zak Rahman](https://github.com/zakriyarahman)

---

💡 **Pro Tip**: Schedule regular optimizations using Laravel's task scheduler for automated maintenance.
