<?php

namespace MySQLOptimizer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use MySQLOptimizer\Actions\OptimizeTablesAction;

class OptimizeTablesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    // Set timeout to 1 hour as optimization can take a long time
    public $timeout = 3600;

    public $uniqueFor = 3600;

    // Prevent queue retry from kicking off a second attempt while the first run is still in progress
    public $backoff = 3600;

    public ?string $database;

    public array $tables;

    public bool $shouldLog;

    public function __construct(?string $database = null, array $tables = [], bool $shouldLog = true)
    {
        $this->database = $database;
        $this->tables = $tables;
        $this->shouldLog = $shouldLog;
    }

    public function uniqueId(): string
    {
        return 'optimize-tables:'.($this->database ?? 'default');
    }

    public function handle(Builder $builder): void
    {
        $action = new OptimizeTablesAction($builder);

        try {
            if ($this->shouldLog) {
                Log::info('MySQLOptimizer: Optimization job started ▶️', [
                    'database' => $this->database ?? 'default',
                    'tables' => $this->tables,
                    'job' => [
                        'id' => $this->job->getJobId(),
                        'attempts' => $this->job->attempts(),
                        'timeout' => $this->job->timeout(),
                    ],
                ]);
            }

            $results = $action->execute(
                $this->database,
                $this->tables,
                function ($table, $success) {
                    if ($this->shouldLog) {
                        $status = $success ? 'SUCCESS' : 'FAILED';
                        Log::info("MySQLOptimizer: Table optimization {$status}: {$table}", [
                            'job' => [
                                'id' => $this->job->getJobId(),
                                'attempts' => $this->job->attempts(),
                                'timeout' => $this->job->timeout(),
                            ],
                        ]);
                    }
                }
            );

            if ($this->shouldLog) {
                $totalTables = $results->count();
                $successfulTables = $results->where('success', true)->count();
                $failedTables = $totalTables - $successfulTables;

                Log::info('MySQLOptimizer: Optimization job completed ✅', [
                    'total_tables' => $totalTables,
                    'successful' => $successfulTables,
                    'failed' => $failedTables,
                    'database' => $this->database ?? 'default',
                    'job' => [
                        'id' => $this->job->getJobId(),
                        'attempts' => $this->job->attempts(),
                        'timeout' => $this->job->timeout(),
                    ],
                ]);
            }
        } catch (\Exception $e) {
            if ($this->shouldLog) {
                Log::error('MySQLOptimizer: Optimization job failed ❌', [
                    'error' => $e->getMessage(),
                    'database' => $this->database ?? 'default',
                    'tables' => $this->tables,
                    'job' => [
                        'id' => $this->job->getJobId(),
                        'attempts' => $this->job->attempts(),
                        'timeout' => $this->job->timeout(),
                    ],
                ]);
            }

            throw $e;
        }
    }

    public function failed(\Exception $exception): void
    {
        if ($this->shouldLog) {
            Log::error('MySQLOptimizer: Optimization job permanently failed ❌', [
                'error' => $exception->getMessage(),
                'database' => $this->database ?? 'default',
                'tables' => $this->tables,
                'job' => [
                    'id' => $this->job->getJobId(),
                    'attempts' => $this->job->attempts(),
                    'timeout' => $this->job->timeout(),
                ],
            ]);
        }
    }
}
