<?php

namespace MySQLOptimizer\ValueObjects;

use Illuminate\Support\Collection;

final class OptimizationTargets
{
    public function __construct(
        public readonly string $database,
        public readonly Collection $tables,
    ) {}
}
