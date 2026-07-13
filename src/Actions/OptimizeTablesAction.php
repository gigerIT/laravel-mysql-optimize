<?php

namespace MySQLOptimizer\Actions;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

class OptimizeTablesAction
{
    protected ResolveOptimizationTargetsAction $resolveTargets;

    protected OptimizeTableAction $optimizeTableAction;

    public function __construct(Builder $builder)
    {
        $this->resolveTargets = new ResolveOptimizationTargetsAction($builder);
        $this->optimizeTableAction = new OptimizeTableAction($builder);
    }

    public function getTableCount(?string $database = null, array $tables = []): int
    {
        return $this->resolveTargets->execute($database, $tables)->tables->count();
    }

    public function execute(?string $database = null, array $tables = [], ?callable $progressCallback = null): Collection
    {
        $targets = $this->resolveTargets->execute($database, $tables);

        return $targets->tables->map(function ($table) use ($targets, $progressCallback) {
            $success = $this->optimizeTable($table, $targets->database);

            if ($progressCallback) {
                $progressCallback($table, $success);
            }

            return $this->resultFor($table, $success);
        });
    }

    public function optimizeTable(string $table, ?string $database = null): bool
    {
        return $this->optimizeTableAction->execute(
            $database ?? config('mysql-optimizer.database'),
            $table,
        );
    }

    private function resultFor(string $table, bool $success): array
    {
        return [
            'table' => $table,
            'success' => $success,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }
}
