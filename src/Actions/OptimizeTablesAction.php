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
     *
     * @var Builder
     */
    protected Builder $db;

    /**
     * Construct
     *
     * @param Builder $builder
     */
    public function __construct(Builder $builder)
    {
        $this->db = $builder;
    }

    /**
     * Get the count of tables that will be optimized
     *
     * @param string|null $database
     * @param array $tables
     * @return int
     */
    public function getTableCount(?string $database = null, array $tables = []): int
    {
        $databaseName = $this->resolveDatabase($database);
        $tablesToOptimize = $this->resolveTables($databaseName, $tables);
        
        return $tablesToOptimize->count();
    }

    /**
     * Execute the optimization action
     *
     * @param string|null $database
     * @param array $tables
     * @param callable|null $progressCallback
     * @return Collection Results of optimization
     */
    public function execute(?string $database = null, array $tables = [], ?callable $progressCallback = null): Collection
    {
        $databaseName = $this->resolveDatabase($database);
        $tablesToOptimize = $this->resolveTables($databaseName, $tables);
        
        return $tablesToOptimize->map(function ($table) use ($progressCallback) {
            $result = $this->optimizeTable($table);
            
            if ($progressCallback) {
                $progressCallback($table, $result);
            }
            
            return [
                'table' => $table,
                'success' => $result,
                'timestamp' => date('Y-m-d H:i:s')
            ];
        });
    }

    /**
     * Get database which need optimization
     *
     * @param string|null $database
     * @return string
     */
    protected function resolveDatabase(?string $database = null): string
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
     *
     * @param string $databaseName
     * @return bool
     */
    private function existsDatabase(string $databaseName): bool
    {
        return $this->db
                    ->newQuery()
                    ->selectRaw('SCHEMA_NAME')
                    ->fromRaw('INFORMATION_SCHEMA.SCHEMATA')
                    ->whereRaw("SCHEMA_NAME = ?", [$databaseName])
                    ->count() > 0;
    }

    /**
     * Get all the tables that need to be optimized
     *
     * @param string $database
     * @param array $tables
     * @return Collection
     */
    private function resolveTables(string $database, array $tables = []): Collection
    {
        $tableList = collect($tables);
        
        if ($tableList->isEmpty()) {
            $tableList = $this->db
                ->newQuery()
                ->selectRaw('TABLE_NAME')
                ->fromRaw('INFORMATION_SCHEMA.TABLES')
                ->whereRaw("TABLE_SCHEMA = ?", [$database])
                ->get();
            return $tableList->pluck('TABLE_NAME');
        }
        
        // Check if the tables exist
        if ($this->existsTables($database, $tableList)) {
            return $tableList;
        }
        
        throw new TableNotFoundException("One or more tables provided doesn't exists.");
    }

    /**
     * Check if the tables exist
     *
     * @param string $database
     * @param Collection $tables
     * @return bool
     */
    private function existsTables(string $database, Collection $tables): bool
    {
        $placeholders = str_repeat('?,', $tables->count() - 1) . '?';
        
        return $this->db
                    ->newQuery()
                    ->fromRaw('INFORMATION_SCHEMA.TABLES')
                    ->whereRaw("TABLE_SCHEMA = ?", [$database])
                    ->whereRaw("TABLE_NAME IN ({$placeholders})", $tables->values()->toArray())
                    ->count() == $tables->count();
    }

    /**
     * Optimize a single table
     *
     * @param string $table
     * @return bool
     */
    public function optimizeTable(string $table): bool
    {
        $result = $this->db->getConnection()->select("OPTIMIZE TABLE `{$table}`");
        return collect($result)->pluck('Msg_text')->contains('OK');
    }
} 