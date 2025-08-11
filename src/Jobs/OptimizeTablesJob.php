<?php

namespace MySQLOptimizer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MySQLOptimizer\Actions\OptimizeTablesAction;
use Illuminate\Support\Facades\Log;

class OptimizeTablesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    //Set timeout to 1 hour as optimization can take a long time
    public $timeout = 3600;


    public ?string $database;


    public array $tables;

    public bool $shouldLog;


    public function __construct(?string $database = null, array $tables = [], bool $shouldLog = true)
    {
        $this->database = $database;
        $this->tables = $tables;
        $this->shouldLog = $shouldLog;
    }

    public function handle(Builder $builder): void
    {
        $action = new OptimizeTablesAction($builder);
        
        try {
            if ($this->shouldLog) {
                Log::info("Optimization job started", [
                    'database' => $this->database ?? 'default',
                    'tables' => $this->tables
                ]);
            }

            $results = $action->execute(
                $this->database,
                $this->tables,
                function ($table, $success) {
                    if ($this->shouldLog) {
                        $status = $success ? 'SUCCESS' : 'FAILED';
                        Log::info("Table optimization {$status}: {$table}");
                    }
                }
            );

            if ($this->shouldLog) {
                $totalTables = $results->count();
                $successfulTables = $results->where('success', true)->count();
                $failedTables = $totalTables - $successfulTables;
                
                Log::info("Optimization job completed", [
                    'total_tables' => $totalTables,
                    'successful' => $successfulTables,
                    'failed' => $failedTables,
                    'database' => $this->database ?? 'default'
                ]);
            }
        } catch (\Exception $e) {
            if ($this->shouldLog) {
                Log::error("Optimization job failed", [
                    'error' => $e->getMessage(),
                    'database' => $this->database ?? 'default',
                    'tables' => $this->tables
                ]);
            }
            
            throw $e;
        }
    }

    public function failed(\Exception $exception): void
    {
        if ($this->shouldLog) {
            Log::error("Optimization job permanently failed", [
                'error' => $exception->getMessage(),
                'database' => $this->database ?? 'default',
                'tables' => $this->tables
            ]);
        }
    }
} 