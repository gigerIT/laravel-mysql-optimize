<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MySQLOptimizer\Actions\OptimizeTablesAction;
use MySQLOptimizer\Exceptions\DatabaseNotFoundException;
use MySQLOptimizer\Exceptions\TableNotFoundException;

beforeEach(function () {
    DB::statement('CREATE TABLE IF NOT EXISTS test_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255),
        email VARCHAR(255)
    ) ENGINE=InnoDB');

    DB::statement('CREATE TABLE IF NOT EXISTS test_posts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255),
        body TEXT
    ) ENGINE=InnoDB');

    DB::table('test_users')->insert([
        ['name' => 'Alice', 'email' => 'alice@example.com'],
        ['name' => 'Bob', 'email' => 'bob@example.com'],
    ]);

    DB::table('test_posts')->insert([
        ['title' => 'First Post', 'body' => str_repeat('content ', 100)],
        ['title' => 'Second Post', 'body' => str_repeat('more content ', 100)],
    ]);

    // Delete rows to create fragmentation
    DB::table('test_users')->where('name', 'Bob')->delete();
    DB::table('test_posts')->where('title', 'Second Post')->delete();
});

afterEach(function () {
    DB::statement('DROP TABLE IF EXISTS test_users');
    DB::statement('DROP TABLE IF EXISTS test_posts');
});

describe('execute', function () {
    it('optimizes all tables in the database', function () {
        $action = new OptimizeTablesAction(DB::query());
        $results = $action->execute();

        expect($results)->toBeInstanceOf(Collection::class);
        expect($results->count())->toBeGreaterThanOrEqual(2);

        $results->each(function ($result) {
            expect($result)->toHaveKeys(['table', 'success', 'timestamp']);
            expect($result['success'])->toBeTrue();
        });
    });

    it('optimizes specific tables', function () {
        $action = new OptimizeTablesAction(DB::query());
        $results = $action->execute(null, ['test_users']);

        expect($results)->toHaveCount(1);
        expect($results->first()['table'])->toBe('test_users');
        expect($results->first()['success'])->toBeTrue();
    });

    it('optimizes multiple specific tables', function () {
        $action = new OptimizeTablesAction(DB::query());
        $results = $action->execute(null, ['test_users', 'test_posts']);

        expect($results)->toHaveCount(2);

        $tableNames = $results->pluck('table')->toArray();
        expect($tableNames)->toContain('test_users', 'test_posts');

        $results->each(function ($result) {
            expect($result['success'])->toBeTrue();
        });
    });

    it('calls progress callback for each table', function () {
        $action = new OptimizeTablesAction(DB::query());
        $callbacks = [];

        $action->execute(null, ['test_users', 'test_posts'], function ($table, $success) use (&$callbacks) {
            $callbacks[] = ['table' => $table, 'success' => $success];
        });

        expect($callbacks)->toHaveCount(2);
        expect(collect($callbacks)->pluck('table')->toArray())->toContain('test_users', 'test_posts');
        expect(collect($callbacks)->pluck('success')->every(fn ($s) => $s === true))->toBeTrue();
    });

    it('returns results with timestamps', function () {
        $action = new OptimizeTablesAction(DB::query());
        $results = $action->execute(null, ['test_users']);

        $timestamp = $results->first()['timestamp'];
        expect($timestamp)->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
    });
});

describe('getTableCount', function () {
    it('returns count of all tables in the database', function () {
        $action = new OptimizeTablesAction(DB::query());
        $count = $action->getTableCount();

        expect($count)->toBeGreaterThanOrEqual(2);
    });

    it('returns count for specific tables', function () {
        $action = new OptimizeTablesAction(DB::query());

        expect($action->getTableCount(null, ['test_users']))->toBe(1);
        expect($action->getTableCount(null, ['test_users', 'test_posts']))->toBe(2);
    });
});

describe('optimizeTable', function () {
    it('successfully optimizes an InnoDB table', function () {
        $action = new OptimizeTablesAction(DB::query());
        $result = $action->optimizeTable('test_users');

        expect($result)->toBeTrue();
    });
});

describe('error handling', function () {
    it('throws DatabaseNotFoundException for non-existent database', function () {
        $action = new OptimizeTablesAction(DB::query());
        $action->execute('non_existent_database_xyz');
    })->throws(DatabaseNotFoundException::class);

    it('throws TableNotFoundException for non-existent tables', function () {
        $action = new OptimizeTablesAction(DB::query());
        $action->execute(null, ['non_existent_table_xyz']);
    })->throws(TableNotFoundException::class);
});
