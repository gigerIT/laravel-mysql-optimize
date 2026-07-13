<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Queue;
use MySQLOptimizer\Console\Commands\Command;
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

            $this->app->when(Command::class)
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

            $this->app->when(Command::class)
                ->needs(Builder::class)
                ->give(fn () => $builder);

            $builder->shouldReceive('newQuery')->andReturn($queryBuilder);
            $queryBuilder->shouldReceive('selectRaw')->with('SCHEMA_NAME')->andReturnSelf();
            $queryBuilder->shouldReceive('fromRaw')->with('INFORMATION_SCHEMA.SCHEMATA')->andReturnSelf();
            $queryBuilder->shouldReceive('whereRaw')->andReturnSelf();
            $queryBuilder->shouldReceive('value')->with('SCHEMA_NAME')->andReturn(null);

            $this->artisan('db:optimize', ['--database' => 'nonexistent_db'])
                ->expectsOutputToContain('Optimization failed')
                ->assertSuccessful();
        });

        it('optimizes specific tables when --table option is provided', function () {
            $builder = Mockery::mock(Builder::class);
            $connection = Mockery::mock(Connection::class);
            $queryBuilder = Mockery::mock(Builder::class);

            $this->app->when(Command::class)
                ->needs(Builder::class)
                ->give(fn () => $builder);

            config(['mysql-optimizer.database' => 'test_db']);

            $builder->shouldReceive('newQuery')->andReturn($queryBuilder);

            // existsTables check
            $queryBuilder->shouldReceive('selectRaw')->with('TABLE_NAME')->andReturnSelf();
            $queryBuilder->shouldReceive('fromRaw')->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
            $queryBuilder->shouldReceive('whereRaw')->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
            $queryBuilder->shouldReceive('whereRaw')->with('TABLE_NAME IN (?)', ['users'])->andReturnSelf();
            $queryBuilder->shouldReceive('get')->andReturn(collect([
                (object) ['TABLE_NAME' => 'users'],
            ]));

            $builder->shouldReceive('getConnection')->andReturn($connection);
            $connection->shouldReceive('select')
                ->with('OPTIMIZE TABLE `test_db`.`users`')
                ->andReturn([(object) ['Msg_text' => 'OK']]);

            $this->artisan('db:optimize', ['--table' => ['users']])
                ->expectsOutputToContain('Optimization Completed: 1/1 tables optimized successfully')
                ->assertSuccessful();
        });
    });

    describe('queued execution', function () {
        beforeEach(function () {
            config(['queue.connections.sync' => ['driver' => 'sync']]);
        });

        it('dispatches job and shows confirmation message', function () {
            Queue::fake();

            $builder = Mockery::mock(Builder::class);
            $this->app->when(Command::class)
                ->needs(Builder::class)
                ->give(fn () => $builder);

            $this->artisan('db:optimize', ['--queued' => true])
                ->expectsOutputToContain('Optimization job queued')
                ->assertSuccessful();

            Queue::assertPushed(OptimizeTablesJob::class);
        });

        it('dispatches job with correct parameters', function () {
            Queue::fake();

            $builder = Mockery::mock(Builder::class);
            $this->app->when(Command::class)
                ->needs(Builder::class)
                ->give(fn () => $builder);

            $this->artisan('db:optimize', [
                '--queued' => true,
                '--database' => 'my_db',
                '--table' => ['users', 'posts'],
                '--no-log' => true,
            ])->assertSuccessful();

            Queue::assertPushed(OptimizeTablesJob::class, function ($job) {
                return $job->database === 'my_db'
                    && $job->tables === ['users', 'posts']
                    && $job->shouldLog === false;
            });
        });

        it('shows table info in confirmation for specific tables', function () {
            Queue::fake();

            $builder = Mockery::mock(Builder::class);
            $this->app->when(Command::class)
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
            Queue::fake();

            $builder = Mockery::mock(Builder::class);
            $this->app->when(Command::class)
                ->needs(Builder::class)
                ->give(fn () => $builder);

            $this->artisan('db:optimize', ['--queued' => true])
                ->expectsOutputToContain('all tables')
                ->assertSuccessful();
        });

        it('does not dispatch with unsafe queue timeout configuration', function () {
            Queue::fake();
            config([
                'queue.default' => 'redis',
                'queue.connections.redis' => ['driver' => 'redis', 'retry_after' => 180],
                'mysql-optimizer.queue.timeout' => 3600,
            ]);

            $builder = Mockery::mock(Builder::class);
            $this->app->when(Command::class)
                ->needs(Builder::class)
                ->give(fn () => $builder);

            $this->artisan('db:optimize', ['--queued' => true])
                ->expectsOutputToContain('retry_after [180]')
                ->assertFailed();

            Queue::assertNothingPushed();
        });

        it('dispatches onto configured connection and queue', function () {
            Queue::fake();
            config(['mysql-optimizer.queue' => [
                'connection' => 'optimizer',
                'name' => 'mysql-optimizer',
                'timeout' => 100,
                'tries' => 1,
                'backoff' => 60,
                'unique_for' => 0,
            ]]);
            config(['queue.connections.optimizer' => ['driver' => 'redis', 'retry_after' => 110]]);

            $builder = Mockery::mock(Builder::class);
            $this->app->when(Command::class)
                ->needs(Builder::class)
                ->give(fn () => $builder);

            $this->artisan('db:optimize', ['--queued' => true])->assertSuccessful();

            Queue::assertPushed(OptimizeTablesJob::class, function ($job) {
                return $job->connection === 'optimizer' && $job->queue === 'mysql-optimizer';
            });
        });

        it('does not dispatch with an invalid configured queue name', function (mixed $name) {
            Queue::fake();
            config(['mysql-optimizer.queue.name' => $name]);

            $builder = Mockery::mock(Builder::class);
            $this->app->when(Command::class)
                ->needs(Builder::class)
                ->give(fn () => $builder);

            $this->artisan('db:optimize', ['--queued' => true])
                ->expectsOutputToContain('queue name')
                ->assertFailed();

            Queue::assertNothingPushed();
        })->with([
            'empty string' => [''],
            'array' => [[]],
            'object' => [(object) ['name' => 'mysql-optimizer']],
        ]);

        it('does not dispatch per table mode without explicit dedicated routing', function () {
            Queue::fake();
            config([
                'mysql-optimizer.queue.per_table' => true,
                'mysql-optimizer.queue.connection' => null,
                'mysql-optimizer.queue.name' => null,
            ]);

            $builder = Mockery::mock(Builder::class);
            $this->app->when(Command::class)
                ->needs(Builder::class)
                ->give(fn () => $builder);

            $this->artisan('db:optimize', ['--queued' => true])
                ->expectsOutputToContain('dedicated one-worker queue')
                ->assertFailed();

            Queue::assertNothingPushed();
        });
    });
});
