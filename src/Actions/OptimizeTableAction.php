<?php

namespace MySQLOptimizer\Actions;

use Illuminate\Database\Query\Builder;

class OptimizeTableAction
{
    public function __construct(protected Builder $builder) {}

    public function execute(string $database, string $table): bool
    {
        $result = $this->builder->getConnection()->select(
            'OPTIMIZE TABLE '.$this->quoteIdentifier($database).'.'.$this->quoteIdentifier($table)
        );

        return collect($result)->pluck('Msg_text')->contains('OK');
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
