<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use MySQLOptimizer\Actions\OptimizeTablesAction;
use MySQLOptimizer\Exceptions\DatabaseNotFoundException;
use MySQLOptimizer\Jobs\OptimizeTablesJob;

describe('db:optimize command', function () {
    it('has correct signature options', function () {
        $command = $this->artisan('db:optimize', ['--help' => true]);
        $command->assertSuccessful();
    });

    describe('synchronous execution', function () {
        it('optimizes all tables and shows completion message', function () {
            $builder = Mockery::mock(Builder::class);
            $connection = Mockery::mock(Connection::class);
            $queryBuilder = Mockery::mock(Builder::class);

            $this->app->when(\MySQLOptimizer\Console\Commands\Command::class)
                ->needs(Builder::class)
                ->give(fn () => $builder);

            config(['mysql-optimizer.database' => 'test_db']);

            $builder->shouldReceive('newQuery')->andReturn($queryBuilder);
            $queryBuilder->shouldReceive('selectRaw')->with('TABLE_NAME')->andReturnSelf();
            $queryBuilder->shouldReceive('fromRaw')->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
            $queryBuilder->shouldReceive('whereRaw')->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
            $queryBuilder->shouldReceive('get')->andReturn(collect([
                (object) ['TABLE_NAME' => 'users'],
                (object) ['TABLE_NAME' => 'posts'],
            ]));

            $builder->shouldReceive('getConnection')->andReturn($connection);
            $connection->shouldReceive('select')->andReturn([(object) ['Msg_text' => 'OK']]);

            $this->artisan('db:optimize')
                ->expectsOutputToContain('Starting Optimization.')
                ->expectsOutputToContain('Optimization Completed: 2/2 tables optimized successfully')
                ->assertSuccessful();
        });

        it('shows error message when database not found', function () {
            $builder = Mockery::mock(Builder::class);
            $queryBuilder = Mockery::mock(Builder::class);

            $this->app->when(\MySQLOptimizer\Console\Commands\Command::class)
                ->needs(Builder::class)
                ->give(fn () => $builder);

            $builder->shouldReceive('newQuery')->andReturn($queryBuilder);
            $queryBuilder->shouldReceive('selectRaw')->with('SCHEMA_NAME')->andReturnSelf();
            $queryBuilder->shouldReceive('fromRaw')->with('INFORMATION_SCHEMA.SCHEMATA')->andReturnSelf();
            $queryBuilder->shouldReceive('whereRaw')->andReturnSelf();
            $queryBuilder->shouldReceive('count')->andReturn(0);

            $this->artisan('db:optimize', ['--database' => 'nonexistent_db'])
                ->expectsOutputToContain('Optimization failed')
                ->assertSuccessful();
        });

        it('optimizes specific tables when --table option is provided', function () {
            $builder = Mockery::mock(Builder::class);
            $connection = Mockery::mock(Connection::class);
            $queryBuilder = Mockery::mock(Builder::class);

            $this->app->when(\MySQLOptimizer\Console\Commands\Command::class)
                ->needs(Builder::class)
                ->give(fn () => $builder);

            config(['mysql-optimizer.database' => 'test_db']);

            $builder->shouldReceive('newQuery')->andReturn($queryBuilder);

            // existsTables check
            $queryBuilder->shouldReceive('fromRaw')->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
            $queryBuilder->shouldReceive('whereRaw')->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
            $queryBuilder->shouldReceive('whereRaw')->with('TABLE_NAME IN (?)', ['users'])->andReturnSelf();
            $queryBuilder->shouldReceive('count')->andReturn(1);

            $builder->shouldReceive('getConnection')->andReturn($connection);
            $connection->shouldReceive('select')
                ->with('OPTIMIZE TABLE `users`')
                ->andReturn([(object) ['Msg_text' => 'OK']]);

            $this->artisan('db:optimize', ['--table' => ['users']])
                ->expectsOutputToContain('Optimization Completed: 1/1 tables optimized successfully')
                ->assertSuccessful();
        });
    });

    describe('queued execution', function () {
        it('dispatches job and shows confirmation message', function () {
            \Illuminate\Support\Facades\Queue::fake();

            $builder = Mockery::mock(Builder::class);
            $this->app->when(\MySQLOptimizer\Console\Commands\Command::class)
                ->needs(Builder::class)
                ->give(fn () => $builder);

            $this->artisan('db:optimize', ['--queued' => true])
                ->expectsOutputToContain('Optimization job queued')
                ->assertSuccessful();

            \Illuminate\Support\Facades\Queue::assertPushed(OptimizeTablesJob::class);
        });

        it('dispatches job with correct parameters', function () {
            \Illuminate\Support\Facades\Queue::fake();

            $builder = Mockery::mock(Builder::class);
            $this->app->when(\MySQLOptimizer\Console\Commands\Command::class)
                ->needs(Builder::class)
                ->give(fn () => $builder);

            $this->artisan('db:optimize', [
                '--queued' => true,
                '--database' => 'my_db',
                '--table' => ['users', 'posts'],
                '--no-log' => true,
            ])->assertSuccessful();

            \Illuminate\Support\Facades\Queue::assertPushed(OptimizeTablesJob::class, function ($job) {
                return $job->database === 'my_db'
                    && $job->tables === ['users', 'posts']
                    && $job->shouldLog === false;
            });
        });

        it('shows table info in confirmation for specific tables', function () {
            \Illuminate\Support\Facades\Queue::fake();

            $builder = Mockery::mock(Builder::class);
            $this->app->when(\MySQLOptimizer\Console\Commands\Command::class)
                ->needs(Builder::class)
                ->give(fn () => $builder);

            $this->artisan('db:optimize', [
                '--queued' => true,
                '--table' => ['users', 'posts'],
            ])
                ->expectsOutputToContain('specified tables (users, posts)')
                ->assertSuccessful();
        });

        it('shows "all tables" in confirmation when no tables specified', function () {
            \Illuminate\Support\Facades\Queue::fake();

            $builder = Mockery::mock(Builder::class);
            $this->app->when(\MySQLOptimizer\Console\Commands\Command::class)
                ->needs(Builder::class)
                ->give(fn () => $builder);

            $this->artisan('db:optimize', ['--queued' => true])
                ->expectsOutputToContain('all tables')
                ->assertSuccessful();
        });
    });
});
