<?php

namespace MySQLOptimizer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MySQLOptimizer\Actions\OptimizeTablesAction;
use MySQLOptimizer\Actions\ResolveOptimizationTargetsAction;
use MySQLOptimizer\Jobs\Concerns\ConfiguresOptimizerQueue;
use MySQLOptimizer\Support\QueueConfigurationValidator;
use Throwable;

class OptimizeTablesJob implements ShouldBeUnique, ShouldQueue
{
    use ConfiguresOptimizerQueue, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 3600;

    public $uniqueFor = 0;

    public $backoff = 3600;

    public ?string $runId = null;

    public bool $perTable = false;

    public function __construct(
        public ?string $database = null,
        public array $tables = [],
        public bool $shouldLog = true,
    ) {
        $this->runId = (string) Str::uuid();
        $this->configureOptimizerQueue();

        $container = Container::getInstance();
        if ($container->bound('config')) {
            $config = $container->make('config');
            $this->perTable = (bool) $config->get('mysql-optimizer.queue.per_table', false);

            $container->make(QueueConfigurationValidator::class)->validatePerTableRouting(
                $this->perTable,
                $config->get('mysql-optimizer.queue.connection'),
                $config->get('mysql-optimizer.queue.name'),
            );
        }

        if ($this->perTable) {
            $this->tries = 1;
        }
    }

    public function uniqueId(): string
    {
        return 'optimize-tables:'.$this->uniqueDatabase();
    }

    public function handle(Builder $builder): void
    {
        $this->normalizeLegacyUniqueFor();
        $this->ensureRunId();
        $connection = $this->validateActualQueueConfiguration();

        if ($this->perTable) {
            $this->dispatchPerTableJobs($builder, $connection);

            return;
        }

        $this->optimizeSequentially($builder);
    }

    public function failed(Throwable $exception): void
    {
        $this->ensureRunId();

        if (! $this->shouldLog) {
            return;
        }

        Log::error(
            $this->perTable
                ? 'MySQLOptimizer: Optimization orchestration permanently failed'
                : 'MySQLOptimizer: Optimization job permanently failed ❌',
            [
                'run_id' => $this->runId,
                'error' => $exception->getMessage(),
                'database' => $this->database ?? 'default',
                'tables' => $this->tables,
                'job' => $this->queueJobContext(),
            ],
        );
    }

    private function optimizeSequentially(Builder $builder): void
    {
        $action = new OptimizeTablesAction($builder);

        try {
            if ($this->shouldLog) {
                Log::info('MySQLOptimizer: Optimization job started ▶️', [
                    'run_id' => $this->runId,
                    'database' => $this->database ?? 'default',
                    'tables' => $this->tables,
                    'job' => $this->queueJobContext(),
                ]);
            }

            $results = $action->execute(
                $this->database,
                $this->tables,
                function ($table, $success) {
                    if ($this->shouldLog) {
                        $status = $success ? 'SUCCESS' : 'FAILED';
                        Log::info("MySQLOptimizer: Table optimization {$status}: {$table}", [
                            'run_id' => $this->runId,
                            'job' => $this->queueJobContext(),
                        ]);
                    }
                },
            );

            if ($this->shouldLog) {
                $totalTables = $results->count();
                $successfulTables = $results->where('success', true)->count();

                Log::info('MySQLOptimizer: Optimization job completed ✅', [
                    'run_id' => $this->runId,
                    'total_tables' => $totalTables,
                    'successful' => $successfulTables,
                    'failed' => $totalTables - $successfulTables,
                    'database' => $this->database ?? 'default',
                    'job' => $this->queueJobContext(),
                ]);
            }
        } catch (Throwable $exception) {
            if ($this->shouldLog) {
                Log::error('MySQLOptimizer: Optimization job failed ❌', [
                    'run_id' => $this->runId,
                    'error' => $exception->getMessage(),
                    'database' => $this->database ?? 'default',
                    'tables' => $this->tables,
                    'job' => $this->queueJobContext(),
                ]);
            }

            throw $exception;
        }
    }

    private function dispatchPerTableJobs(Builder $builder, string $connection): void
    {
        $queue = $this->actualQueueName();

        if ($this->shouldLog) {
            Log::info('MySQLOptimizer: Optimization orchestration started', [
                'run_id' => $this->runId,
                'database' => $this->database ?? 'default',
                'tables' => $this->tables,
                'job' => $this->queueJobContext(),
            ]);
        }

        $targets = (new ResolveOptimizationTargetsAction($builder))
            ->execute($this->database, $this->tables);

        foreach ($targets->tables as $table) {
            $child = new OptimizeTableJob(
                $targets->database,
                $table,
                $this->shouldLog,
                $this->runId,
            );
            $child->routeTo($connection, $queue);
            dispatch($child);
        }

        if ($this->shouldLog) {
            Log::info('MySQLOptimizer: Optimization jobs dispatched', [
                'run_id' => $this->runId,
                'database' => $targets->database,
                'table_count' => $targets->tables->count(),
                'job' => $this->queueJobContext(),
            ]);
        }
    }

    private function ensureRunId(): string
    {
        if (! isset($this->runId) || $this->runId === '') {
            $this->runId = (string) Str::uuid();
        }

        return $this->runId;
    }

    private function normalizeLegacyUniqueFor(): void
    {
        if ((! isset($this->runId) || $this->runId === null) && $this->uniqueFor === $this->timeout) {
            // v1.5.1 serialized uniqueFor equal to timeout. Its lock TTL was fixed at dispatch
            // and cannot be repaired while executing; tolerate only that legacy validation value.
            $this->uniqueFor = 0;
        }
    }

    private function uniqueDatabase(): string
    {
        if ($this->database !== null && $this->database !== 'default') {
            return $this->database;
        }

        $container = Container::getInstance();
        if ($container->bound('config')) {
            $database = $container->make('config')->get('mysql-optimizer.database');

            if (is_string($database) && $database !== '') {
                return $database;
            }
        }

        return 'default';
    }
}
