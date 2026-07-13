<?php

namespace MySQLOptimizer\Actions;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use MySQLOptimizer\Exceptions\DatabaseNotFoundException;
use MySQLOptimizer\Exceptions\TableNotFoundException;
use MySQLOptimizer\ValueObjects\OptimizationTargets;

class ResolveOptimizationTargetsAction
{
    public function __construct(protected Builder $builder) {}

    public function execute(?string $database = null, array $tables = []): OptimizationTargets
    {
        $database = $this->resolveDatabase($database);
        $tables = $this->resolveTables($database, $tables);

        return new OptimizationTargets($database, $tables);
    }

    private function resolveDatabase(?string $database): string
    {
        if ($database === null || $database === 'default') {
            return config('mysql-optimizer.database');
        }

        $canonicalDatabase = $this->canonicalDatabase($database);
        if ($canonicalDatabase !== null) {
            return $canonicalDatabase;
        }

        throw new DatabaseNotFoundException("This database {$database} doesn't exists.");
    }

    private function canonicalDatabase(string $database): ?string
    {
        $canonicalDatabase = $this->builder
            ->newQuery()
            ->selectRaw('SCHEMA_NAME')
            ->fromRaw('INFORMATION_SCHEMA.SCHEMATA')
            ->whereRaw('SCHEMA_NAME = ?', [$database])
            ->value('SCHEMA_NAME');

        return is_string($canonicalDatabase) ? $canonicalDatabase : null;
    }

    private function resolveTables(string $database, array $tables): Collection
    {
        $tableList = collect($tables);

        if ($tableList->isEmpty()) {
            return $this->builder
                ->newQuery()
                ->selectRaw('TABLE_NAME')
                ->fromRaw('INFORMATION_SCHEMA.TABLES')
                ->whereRaw('TABLE_SCHEMA = ?', [$database])
                ->get()
                ->pluck('TABLE_NAME');
        }

        $canonicalTables = $this->canonicalRequestedTables($database, $tableList);

        return $this->orderCanonicalTables($tableList, $canonicalTables);
    }

    private function canonicalRequestedTables(string $database, Collection $tables): Collection
    {
        $placeholders = str_repeat('?,', $tables->count() - 1).'?';

        return $this->builder
            ->newQuery()
            ->selectRaw('TABLE_NAME')
            ->fromRaw('INFORMATION_SCHEMA.TABLES')
            ->whereRaw('TABLE_SCHEMA = ?', [$database])
            ->whereRaw("TABLE_NAME IN ({$placeholders})", $tables->values()->toArray())
            ->get()
            ->pluck('TABLE_NAME');
    }

    private function orderCanonicalTables(Collection $requestedTables, Collection $canonicalTables): Collection
    {
        $usedCanonicalTables = [];

        return $requestedTables->map(function ($requestedTable) use ($canonicalTables, &$usedCanonicalTables) {
            if (! is_string($requestedTable)) {
                throw new TableNotFoundException("One or more tables provided doesn't exists.");
            }

            $exactMatches = $canonicalTables
                ->filter(fn ($canonicalTable) => is_string($canonicalTable) && $canonicalTable === $requestedTable)
                ->values();

            $matches = $exactMatches->isNotEmpty()
                ? $exactMatches
                : $canonicalTables
                    ->filter(fn ($canonicalTable) => is_string($canonicalTable)
                        && strcasecmp($canonicalTable, $requestedTable) === 0)
                    ->values();

            if ($matches->count() !== 1 || in_array($matches->first(), $usedCanonicalTables, true)) {
                throw new TableNotFoundException("One or more tables provided doesn't exists.");
            }

            $canonicalTable = $matches->first();
            $usedCanonicalTables[] = $canonicalTable;

            return $canonicalTable;
        });
    }
}
