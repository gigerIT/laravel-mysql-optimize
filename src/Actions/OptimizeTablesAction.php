<?php

namespace MySQLOptimizer\Actions;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use MySQLOptimizer\Exceptions\DatabaseNotFoundException;
use MySQLOptimizer\Exceptions\TableNotFoundException;

class OptimizeTablesAction
{
    /**
     * The database query builder instance.
     */
    protected Builder $db;

    /**
     * Construct
     */
    public function __construct(Builder $builder)
    {
        $this->db = $builder;
    }

    /**
     * Get the count of tables that will be optimized
     */
    public function getTableCount(?string $database = null, array $tables = []): int
    {
        return $this->targetTables($this->resolveDatabase($database), $tables)->count();
    }

    /**
     * Execute the optimization action
     *
     * @return Collection Results of optimization
     */
    public function execute(?string $database = null, array $tables = [], ?callable $progressCallback = null): Collection
    {
        $tablesToOptimize = $this->targetTables($this->resolveDatabase($database), $tables);

        return $tablesToOptimize->map(function ($table) use ($progressCallback) {
            $success = $this->optimizeTable($table);

            if ($progressCallback) {
                $progressCallback($table, $success);
            }

            return $this->resultFor($table, $success);
        });
    }

    /**
     * Get database which need optimization
     */
    protected function resolveDatabase(?string $database = null): string
    {
        return $this->targetDatabase($database);
    }

    /**
     * Get database which need optimization
     */
    private function targetDatabase(?string $database = null): string
    {
        if ($database === null || $database === 'default') {
            return \config('mysql-optimizer.database');
        }

        // Check if the database exists
        if ($this->existsDatabase($database)) {
            return $database;
        }

        throw new DatabaseNotFoundException("This database {$database} doesn't exists.");
    }

    /**
     * Check if the database exists
     */
    private function existsDatabase(string $databaseName): bool
    {
        return $this->db
            ->newQuery()
            ->selectRaw('SCHEMA_NAME')
            ->fromRaw('INFORMATION_SCHEMA.SCHEMATA')
            ->whereRaw('SCHEMA_NAME = ?', [$databaseName])
            ->count() > 0;
    }

    /**
     * Get all the tables that need to be optimized
     */
    private function targetTables(string $database, array $tables = []): Collection
    {
        $tableList = collect($tables);

        if ($tableList->isEmpty()) {
            return $this->allTablesFor($database);
        }

        // Check if the tables exist
        if ($this->requestedTablesExist($database, $tableList)) {
            return $tableList;
        }

        throw new TableNotFoundException("One or more tables provided doesn't exists.");
    }

    /**
     * Get all tables in the database
     */
    private function allTablesFor(string $database): Collection
    {
        return $this->db
            ->newQuery()
            ->selectRaw('TABLE_NAME')
            ->fromRaw('INFORMATION_SCHEMA.TABLES')
            ->whereRaw('TABLE_SCHEMA = ?', [$database])
            ->get()
            ->pluck('TABLE_NAME');
    }

    /**
     * Check if the tables exist
     */
    private function requestedTablesExist(string $database, Collection $tables): bool
    {
        $placeholders = str_repeat('?,', $tables->count() - 1).'?';

        return $this->db
            ->newQuery()
            ->fromRaw('INFORMATION_SCHEMA.TABLES')
            ->whereRaw('TABLE_SCHEMA = ?', [$database])
            ->whereRaw("TABLE_NAME IN ({$placeholders})", $tables->values()->toArray())
            ->count() == $tables->count();
    }

    /**
     * Optimize a single table
     */
    public function optimizeTable(string $table): bool
    {
        $result = $this->db->getConnection()->select('OPTIMIZE TABLE '.$this->quoteIdentifier($table));

        return collect($result)->pluck('Msg_text')->contains('OK');
    }

    /**
     * Build a single optimization result row
     */
    private function resultFor(string $table, bool $success): array
    {
        return [
            'table' => $table,
            'success' => $success,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Quote a MySQL identifier
     */
    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
