<?php

namespace MySQLOptimizer\Console\Commands;

use Illuminate\Console\Command as BaseCommand;
use Illuminate\Database\Query\Builder;
use MySQLOptimizer\Actions\OptimizeTablesAction;
use MySQLOptimizer\Jobs\OptimizeTablesJob;
use Symfony\Component\Console\Helper\ProgressBar;

class Command extends BaseCommand
{
    /**
     * The database query builder instance.
     */
    protected Builder $db;

    /**
     * The progress bar instance.
     */
    protected ProgressBar $progress;

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Optimize table/s of the database';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'Table optimizer for database';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:optimize
                        {--database=default : Default database is set in the config. Database that needs to be optimized.}
                        {--table=* : Defaulting to all tables in the default database.}
                        {--queued : Queue the optimization job instead of running synchronously}
                        {--no-log : Disable logging when using queue option}';

    /**
     * Construct
     */
    public function __construct(Builder $builder)
    {
        $this->db = $builder;
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $database = $this->option('database');
        $tables = $this->option('table');
        $isQueued = $this->option('queued');
        $shouldLog = ! $this->option('no-log');

        if ($isQueued) {
            $this->handleQueuedOptimization($database, $tables, $shouldLog);
        } else {
            $this->handleSynchronousOptimization($database, $tables);
        }
    }

    /**
     * Handle queued optimization
     */
    protected function handleQueuedOptimization(?string $database, array $tables, bool $shouldLog): void
    {
        $job = new OptimizeTablesJob($database, $tables, $shouldLog);
        OptimizeTablesJob::dispatch($database, $tables, $shouldLog);

        $databaseName = $database === 'default' ? 'default database' : "database '{$database}'";
        $tableInfo = empty($tables) ? 'all tables' : 'specified tables ('.implode(', ', $tables).')';

        $this->info("Optimization job queued for {$tableInfo} in {$databaseName}");
    }

    /**
     * Handle synchronous optimization
     */
    protected function handleSynchronousOptimization(?string $database, array $tables): void
    {
        $this->info('Starting Optimization.');

        $action = new OptimizeTablesAction($this->db);

        try {
            // Set up progress bar with correct count
            $tableCount = $action->getTableCount($database, $tables);
            $this->progress = $this->output->createProgressBar($tableCount);
            $this->progress->start();

            // Execute optimization with progress callback
            $results = $action->execute(
                $database,
                $tables,
                function ($table, $success) {
                    if ($success) {
                        $this->progress->advance();
                    }
                }
            );

            $this->progress->finish();

            $successful = $results->where('success', true)->count();
            $total = $results->count();

            $this->info(PHP_EOL."Optimization Completed: {$successful}/{$total} tables optimized successfully");

        } catch (\Exception $e) {
            $this->error('Optimization failed: '.$e->getMessage());
        }
    }
}
