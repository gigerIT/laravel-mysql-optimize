<?php

use MySQLOptimizer\Exceptions\DatabaseNotFoundException;
use MySQLOptimizer\Exceptions\TableNotFoundException;

describe('DatabaseNotFoundException', function () {
    it('extends base Exception class', function () {
        $exception = new DatabaseNotFoundException('Database not found');

        expect($exception)->toBeInstanceOf(Exception::class)
            ->and($exception)->toBeInstanceOf(DatabaseNotFoundException::class)
            ->and($exception->getMessage())->toBe('Database not found');
    });

    it('can be thrown and caught', function () {
        $this->expectException(DatabaseNotFoundException::class);
        $this->expectExceptionMessage('test_db not found');

        throw new DatabaseNotFoundException('test_db not found');
    });
});

describe('TableNotFoundException', function () {
    it('extends base Exception class', function () {
        $exception = new TableNotFoundException('Table not found');

        expect($exception)->toBeInstanceOf(Exception::class)
            ->and($exception)->toBeInstanceOf(TableNotFoundException::class)
            ->and($exception->getMessage())->toBe('Table not found');
    });

    it('can be thrown and caught', function () {
        $this->expectException(TableNotFoundException::class);
        $this->expectExceptionMessage('users table not found');

        throw new TableNotFoundException('users table not found');
    });
});
