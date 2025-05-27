# Laravel MySQL Optimizer

A Laravel package that optimizes MySQL database tables by reorganizing data and rebuilding indexes to improve performance and reduce disk usage.

## Why Use This Package?

MySQL's `OPTIMIZE TABLE` statement reorganizes tables and compacts wasted space, resulting in:

- **Faster queries** through improved data packing and reduced fragmentation
- **Less disk I/O** for full table scans
- **Reduced storage footprint** through better space utilization

Perfect for tables with frequent `INSERT`, `UPDATE`, and `DELETE` operations.

## Installation

```bash
composer require gigerit/laravel-mysql-optimizer
```

## Quick Start

Optimize all tables in your default database:

```bash
php artisan db:optimize
```

## Configuration

Publish the configuration file to customize settings:

```bash
php artisan vendor:publish --provider="MySQLOptimizer\ServiceProvider" --tag=config
```

The package uses your `DB_DATABASE` environment variable by default.

## Usage Examples

### Optimize Specific Database

```bash
php artisan db:optimize --database=my_custom_db
```

### Optimize Specific Tables

```bash
php artisan db:optimize --table=users --table=orders
```

### Combine Options

```bash
php artisan db:optimize --database=analytics --table=user_events --table=page_views
```

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
