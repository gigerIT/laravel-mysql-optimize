<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::statement('CREATE TABLE IF NOT EXISTS test_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255)
    ) ENGINE=InnoDB');

    DB::statement('CREATE TABLE IF NOT EXISTS test_posts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255)
    ) ENGINE=InnoDB');

    DB::table('test_users')->insert(['name' => 'Test User']);
    DB::table('test_posts')->insert(['title' => 'Test Post']);
});

afterEach(function () {
    DB::statement('DROP TABLE IF EXISTS test_users');
    DB::statement('DROP TABLE IF EXISTS test_posts');
});

describe('db:optimize command', function () {
    it('optimizes all tables synchronously', function () {
        $this->artisan('db:optimize')
            ->expectsOutputToContain('Starting Optimization')
            ->expectsOutputToContain('Optimization Completed')
            ->assertExitCode(0);
    });

    it('optimizes specific tables synchronously', function () {
        $this->artisan('db:optimize', ['--table' => ['test_users']])
            ->expectsOutputToContain('Starting Optimization')
            ->expectsOutputToContain('1/1 tables optimized successfully')
            ->assertExitCode(0);
    });

    it('shows error for non-existent database', function () {
        $this->artisan('db:optimize', ['--database' => 'non_existent_db_xyz'])
            ->expectsOutputToContain('Optimization failed')
            ->assertExitCode(0);
    });

    it('shows error for non-existent table', function () {
        $this->artisan('db:optimize', ['--table' => ['non_existent_table_xyz']])
            ->expectsOutputToContain('Optimization failed')
            ->assertExitCode(0);
    });
});
