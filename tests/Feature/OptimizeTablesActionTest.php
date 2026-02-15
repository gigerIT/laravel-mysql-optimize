<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use MySQLOptimizer\Actions\OptimizeTablesAction;
use MySQLOptimizer\Exceptions\DatabaseNotFoundException;
use MySQLOptimizer\Exceptions\TableNotFoundException;

beforeEach(function () {
    $this->builder = Mockery::mock(Builder::class);
    $this->connection = Mockery::mock(Connection::class);
    $this->action = new OptimizeTablesAction($this->builder);
});

describe('resolveDatabase', function () {
    it('uses config database when null is passed', function () {
        config(['mysql-optimizer.database' => 'test_db']);

        $queryBuilder = Mockery::mock(Builder::class);
        $this->builder->shouldReceive('newQuery')->andReturn($queryBuilder);
        $queryBuilder->shouldReceive('selectRaw')->with('TABLE_NAME')->andReturnSelf();
        $queryBuilder->shouldReceive('fromRaw')->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
        $queryBuilder->shouldReceive('whereRaw')->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
        $queryBuilder->shouldReceive('get')->andReturn(collect([
            (object) ['TABLE_NAME' => 'users'],
        ]));

        $this->builder->shouldReceive('getConnection')->andReturn($this->connection);
        $this->connection->shouldReceive('select')
            ->with('OPTIMIZE TABLE `users`')
            ->andReturn([(object) ['Msg_text' => 'OK']]);

        $results = $this->action->execute(null);

        expect($results)->toBeInstanceOf(Collection::class)
            ->and($results->first()['table'])->toBe('users');
    });

    it('uses config database when "default" is passed', function () {
        config(['mysql-optimizer.database' => 'test_db']);

        $queryBuilder = Mockery::mock(Builder::class);
        $this->builder->shouldReceive('newQuery')->andReturn($queryBuilder);
        $queryBuilder->shouldReceive('selectRaw')->with('TABLE_NAME')->andReturnSelf();
        $queryBuilder->shouldReceive('fromRaw')->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
        $queryBuilder->shouldReceive('whereRaw')->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
        $queryBuilder->shouldReceive('get')->andReturn(collect([
            (object) ['TABLE_NAME' => 'posts'],
        ]));

        $this->builder->shouldReceive('getConnection')->andReturn($this->connection);
        $this->connection->shouldReceive('select')
            ->with('OPTIMIZE TABLE `posts`')
            ->andReturn([(object) ['Msg_text' => 'OK']]);

        $results = $this->action->execute('default');

        expect($results->first()['table'])->toBe('posts');
    });

    it('throws DatabaseNotFoundException for non-existent database', function () {
        $queryBuilder = Mockery::mock(Builder::class);
        $this->builder->shouldReceive('newQuery')->andReturn($queryBuilder);
        $queryBuilder->shouldReceive('selectRaw')->with('SCHEMA_NAME')->andReturnSelf();
        $queryBuilder->shouldReceive('fromRaw')->with('INFORMATION_SCHEMA.SCHEMATA')->andReturnSelf();
        $queryBuilder->shouldReceive('whereRaw')->with('SCHEMA_NAME = ?', ['nonexistent_db'])->andReturnSelf();
        $queryBuilder->shouldReceive('count')->andReturn(0);

        $this->action->execute('nonexistent_db');
    })->throws(DatabaseNotFoundException::class, "This database nonexistent_db doesn't exists.");

    it('resolves a valid custom database', function () {
        $queryBuilder = Mockery::mock(Builder::class);
        $this->builder->shouldReceive('newQuery')->andReturn($queryBuilder);

        // existsDatabase check
        $queryBuilder->shouldReceive('selectRaw')->with('SCHEMA_NAME')->andReturnSelf();
        $queryBuilder->shouldReceive('fromRaw')->with('INFORMATION_SCHEMA.SCHEMATA')->andReturnSelf();
        $queryBuilder->shouldReceive('whereRaw')->with('SCHEMA_NAME = ?', ['custom_db'])->andReturnSelf();
        $queryBuilder->shouldReceive('count')->andReturn(1);

        // resolveTables - get all tables
        $queryBuilder->shouldReceive('selectRaw')->with('TABLE_NAME')->andReturnSelf();
        $queryBuilder->shouldReceive('fromRaw')->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
        $queryBuilder->shouldReceive('whereRaw')->with('TABLE_SCHEMA = ?', ['custom_db'])->andReturnSelf();
        $queryBuilder->shouldReceive('get')->andReturn(collect([
            (object) ['TABLE_NAME' => 'users'],
        ]));

        $this->builder->shouldReceive('getConnection')->andReturn($this->connection);
        $this->connection->shouldReceive('select')
            ->with('OPTIMIZE TABLE `users`')
            ->andReturn([(object) ['Msg_text' => 'OK']]);

        $results = $this->action->execute('custom_db');

        expect($results)->toHaveCount(1)
            ->and($results->first()['table'])->toBe('users');
    });
});

describe('resolveTables', function () {
    it('fetches all tables when none specified', function () {
        config(['mysql-optimizer.database' => 'test_db']);

        $queryBuilder = Mockery::mock(Builder::class);
        $this->builder->shouldReceive('newQuery')->andReturn($queryBuilder);
        $queryBuilder->shouldReceive('selectRaw')->with('TABLE_NAME')->andReturnSelf();
        $queryBuilder->shouldReceive('fromRaw')->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
        $queryBuilder->shouldReceive('whereRaw')->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
        $queryBuilder->shouldReceive('get')->andReturn(collect([
            (object) ['TABLE_NAME' => 'users'],
            (object) ['TABLE_NAME' => 'posts'],
            (object) ['TABLE_NAME' => 'comments'],
        ]));

        $this->builder->shouldReceive('getConnection')->andReturn($this->connection);
        $this->connection->shouldReceive('select')->andReturn([(object) ['Msg_text' => 'OK']]);

        $results = $this->action->execute(null);

        expect($results)->toHaveCount(3);
    });

    it('uses specified tables when provided', function () {
        config(['mysql-optimizer.database' => 'test_db']);

        $queryBuilder = Mockery::mock(Builder::class);
        $this->builder->shouldReceive('newQuery')->andReturn($queryBuilder);

        // existsTables check
        $queryBuilder->shouldReceive('fromRaw')->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
        $queryBuilder->shouldReceive('whereRaw')->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
        $queryBuilder->shouldReceive('whereRaw')->with('TABLE_NAME IN (?,?)', ['users', 'posts'])->andReturnSelf();
        $queryBuilder->shouldReceive('count')->andReturn(2);

        $this->builder->shouldReceive('getConnection')->andReturn($this->connection);
        $this->connection->shouldReceive('select')->andReturn([(object) ['Msg_text' => 'OK']]);

        $results = $this->action->execute(null, ['users', 'posts']);

        expect($results)->toHaveCount(2)
            ->and($results->first()['table'])->toBe('users')
            ->and($results->last()['table'])->toBe('posts');
    });

    it('throws TableNotFoundException when specified tables do not exist', function () {
        config(['mysql-optimizer.database' => 'test_db']);

        $queryBuilder = Mockery::mock(Builder::class);
        $this->builder->shouldReceive('newQuery')->andReturn($queryBuilder);

        // existsTables check - returns fewer than requested
        $queryBuilder->shouldReceive('fromRaw')->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
        $queryBuilder->shouldReceive('whereRaw')->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
        $queryBuilder->shouldReceive('whereRaw')->with('TABLE_NAME IN (?)', ['nonexistent'])->andReturnSelf();
        $queryBuilder->shouldReceive('count')->andReturn(0);

        $this->action->execute(null, ['nonexistent']);
    })->throws(TableNotFoundException::class, "One or more tables provided doesn't exists.");
});

describe('execute', function () {
    it('returns collection with table, success, and timestamp keys', function () {
        config(['mysql-optimizer.database' => 'test_db']);

        $queryBuilder = Mockery::mock(Builder::class);
        $this->builder->shouldReceive('newQuery')->andReturn($queryBuilder);
        $queryBuilder->shouldReceive('selectRaw')->with('TABLE_NAME')->andReturnSelf();
        $queryBuilder->shouldReceive('fromRaw')->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
        $queryBuilder->shouldReceive('whereRaw')->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
        $queryBuilder->shouldReceive('get')->andReturn(collect([
            (object) ['TABLE_NAME' => 'users'],
        ]));

        $this->builder->shouldReceive('getConnection')->andReturn($this->connection);
        $this->connection->shouldReceive('select')
            ->with('OPTIMIZE TABLE `users`')
            ->andReturn([(object) ['Msg_text' => 'OK']]);

        $results = $this->action->execute(null);

        expect($results->first())
            ->toHaveKeys(['table', 'success', 'timestamp'])
            ->and($results->first()['table'])->toBe('users')
            ->and($results->first()['success'])->toBeTrue()
            ->and($results->first()['timestamp'])->toMatch('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/');
    });

    it('calls progress callback for each table', function () {
        config(['mysql-optimizer.database' => 'test_db']);

        $queryBuilder = Mockery::mock(Builder::class);
        $this->builder->shouldReceive('newQuery')->andReturn($queryBuilder);
        $queryBuilder->shouldReceive('selectRaw')->with('TABLE_NAME')->andReturnSelf();
        $queryBuilder->shouldReceive('fromRaw')->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
        $queryBuilder->shouldReceive('whereRaw')->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
        $queryBuilder->shouldReceive('get')->andReturn(collect([
            (object) ['TABLE_NAME' => 'users'],
            (object) ['TABLE_NAME' => 'posts'],
        ]));

        $this->builder->shouldReceive('getConnection')->andReturn($this->connection);
        $this->connection->shouldReceive('select')->andReturn([(object) ['Msg_text' => 'OK']]);

        $callbackCalls = [];
        $this->action->execute(null, [], function ($table, $result) use (&$callbackCalls) {
            $callbackCalls[] = ['table' => $table, 'result' => $result];
        });

        expect($callbackCalls)->toHaveCount(2)
            ->and($callbackCalls[0]['table'])->toBe('users')
            ->and($callbackCalls[0]['result'])->toBeTrue()
            ->and($callbackCalls[1]['table'])->toBe('posts')
            ->and($callbackCalls[1]['result'])->toBeTrue();
    });

    it('handles failed optimization', function () {
        config(['mysql-optimizer.database' => 'test_db']);

        $queryBuilder = Mockery::mock(Builder::class);
        $this->builder->shouldReceive('newQuery')->andReturn($queryBuilder);
        $queryBuilder->shouldReceive('selectRaw')->with('TABLE_NAME')->andReturnSelf();
        $queryBuilder->shouldReceive('fromRaw')->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
        $queryBuilder->shouldReceive('whereRaw')->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
        $queryBuilder->shouldReceive('get')->andReturn(collect([
            (object) ['TABLE_NAME' => 'users'],
        ]));

        $this->builder->shouldReceive('getConnection')->andReturn($this->connection);
        $this->connection->shouldReceive('select')
            ->with('OPTIMIZE TABLE `users`')
            ->andReturn([(object) ['Msg_text' => 'Table does not support optimize']]);

        $results = $this->action->execute(null);

        expect($results->first()['success'])->toBeFalse();
    });
});

describe('getTableCount', function () {
    it('returns the number of tables to optimize', function () {
        config(['mysql-optimizer.database' => 'test_db']);

        $queryBuilder = Mockery::mock(Builder::class);
        $this->builder->shouldReceive('newQuery')->andReturn($queryBuilder);
        $queryBuilder->shouldReceive('selectRaw')->with('TABLE_NAME')->andReturnSelf();
        $queryBuilder->shouldReceive('fromRaw')->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
        $queryBuilder->shouldReceive('whereRaw')->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
        $queryBuilder->shouldReceive('get')->andReturn(collect([
            (object) ['TABLE_NAME' => 'users'],
            (object) ['TABLE_NAME' => 'posts'],
            (object) ['TABLE_NAME' => 'comments'],
        ]));

        $count = $this->action->getTableCount(null);

        expect($count)->toBe(3);
    });

    it('returns count for specific tables', function () {
        config(['mysql-optimizer.database' => 'test_db']);

        $queryBuilder = Mockery::mock(Builder::class);
        $this->builder->shouldReceive('newQuery')->andReturn($queryBuilder);

        // existsTables check
        $queryBuilder->shouldReceive('fromRaw')->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
        $queryBuilder->shouldReceive('whereRaw')->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
        $queryBuilder->shouldReceive('whereRaw')->with('TABLE_NAME IN (?,?)', ['users', 'posts'])->andReturnSelf();
        $queryBuilder->shouldReceive('count')->andReturn(2);

        $count = $this->action->getTableCount(null, ['users', 'posts']);

        expect($count)->toBe(2);
    });
});

describe('optimizeTable', function () {
    it('returns true when optimization succeeds', function () {
        $this->builder->shouldReceive('getConnection')->andReturn($this->connection);
        $this->connection->shouldReceive('select')
            ->with('OPTIMIZE TABLE `users`')
            ->andReturn([(object) ['Msg_text' => 'OK']]);

        $result = $this->action->optimizeTable('users');

        expect($result)->toBeTrue();
    });

    it('returns false when optimization fails', function () {
        $this->builder->shouldReceive('getConnection')->andReturn($this->connection);
        $this->connection->shouldReceive('select')
            ->with('OPTIMIZE TABLE `users`')
            ->andReturn([(object) ['Msg_text' => 'Table does not support optimize']]);

        $result = $this->action->optimizeTable('users');

        expect($result)->toBeFalse();
    });

    it('returns true when result contains OK among multiple messages', function () {
        $this->builder->shouldReceive('getConnection')->andReturn($this->connection);
        $this->connection->shouldReceive('select')
            ->with('OPTIMIZE TABLE `users`')
            ->andReturn([
                (object) ['Msg_text' => 'Table does not support optimize, doing recreate + analyze instead'],
                (object) ['Msg_text' => 'OK'],
            ]);

        $result = $this->action->optimizeTable('users');

        expect($result)->toBeTrue();
    });
});
