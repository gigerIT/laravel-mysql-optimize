<?php

namespace MySQLOptimizer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use MySQLOptimizer\Actions\OptimizeTableAction;
use MySQLOptimizer\Jobs\Concerns\ConfiguresOptimizerQueue;
use Throwable;

class OptimizeTableJob implements ShouldBeUnique, ShouldQueue
{
    use ConfiguresOptimizerQueue, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 3600;

    public $uniqueFor = 0;

    public $backoff = 3600;

    public function __construct(
        public string $database,
        public string $table,
        public bool $shouldLog,
        public string $runId,
    ) {
        $this->configureOptimizerQueue();
    }

    public function uniqueId(): string
    {
        return 'optimize-table:'.hash(
            'sha256',
            json_encode([$this->database, $this->table], JSON_THROW_ON_ERROR),
        );
    }

    public function handle(OptimizeTableAction $action): void
    {
        $this->validateActualQueueConfiguration();
        $startedAt = hrtime(true);

        if ($this->shouldLog) {
            Log::info('MySQLOptimizer: Table optimization started', $this->logContext());
        }

        try {
            $success = $action->execute($this->database, $this->table);
        } catch (Throwable $exception) {
            if ($this->shouldLog) {
                Log::error('MySQLOptimizer: Table optimization attempt failed', [
                    ...$this->logContext(),
                    'error' => $exception->getMessage(),
                    'duration_ms' => (hrtime(true) - $startedAt) / 1_000_000,
                ]);
            }

            throw $exception;
        }

        if ($this->shouldLog) {
            Log::info('MySQLOptimizer: Table optimization completed', [
                ...$this->logContext(),
                'success' => $success,
                'duration_ms' => (hrtime(true) - $startedAt) / 1_000_000,
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        if ($this->shouldLog) {
            Log::error('MySQLOptimizer: Table optimization permanently failed', [
                ...$this->logContext(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function logContext(): array
    {
        return [
            'run_id' => $this->runId,
            'database' => $this->database,
            'table' => $this->table,
            'attempt' => $this->job?->attempts(),
            'job' => $this->queueJobContext(),
        ];
    }
}
