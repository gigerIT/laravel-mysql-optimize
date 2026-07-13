<?php

use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use MySQLOptimizer\Exceptions\InvalidQueueConfigurationException;
use MySQLOptimizer\Jobs\OptimizeTableJob;
use MySQLOptimizer\Jobs\OptimizeTablesJob;

describe('OptimizeTablesJob queue configuration', function () {
    it('uses package runtime defaults', function () {
        $job = new OptimizeTablesJob;

        expect($job->tries)->toBe(1)
            ->and($job->timeout)->toBe(3600)
            ->and($job->uniqueFor)->toBe(0)
            ->and($job->backoff)->toBe(3600)
            ->and($job->connection)->toBeNull()
            ->and($job->queue)->toBeNull();
    });

    it('uses configured runtime properties and routing', function () {
        config(['mysql-optimizer.queue' => [
            'connection' => 'optimizer',
            'name' => 'mysql-optimizer',
            'timeout' => 5400,
            'tries' => 2,
            'backoff' => 120,
            'unique_for' => 6000,
        ]]);

        $job = new OptimizeTablesJob('database', ['users'], false);

        expect($job->timeout)->toBe(5400)
            ->and($job->tries)->toBe(2)
            ->and($job->backoff)->toBe(120)
            ->and($job->uniqueFor)->toBe(6000)
            ->and($job->connection)->toBe('optimizer')
            ->and($job->queue)->toBe('mysql-optimizer');
    });

    it('keeps runtime defaults with partial queue configuration', function () {
        config(['mysql-optimizer.queue' => [
            'name' => 'mysql-optimizer',
        ]]);

        $job = new OptimizeTablesJob;

        expect($job->timeout)->toBe(3600)
            ->and($job->tries)->toBe(1)
            ->and($job->backoff)->toBe(3600)
            ->and($job->uniqueFor)->toBe(0)
            ->and($job->connection)->toBeNull()
            ->and($job->queue)->toBe('mysql-optimizer');
    });

    it('rejects invalid runtime properties', function (string $key, mixed $value) {
        config(["mysql-optimizer.queue.{$key}" => $value]);

        expect(fn () => new OptimizeTablesJob)
            ->toThrow(InvalidQueueConfigurationException::class);
    })->with([
        'non-integer timeout' => ['timeout', '3600'],
        'zero timeout' => ['timeout', 0],
        'zero tries' => ['tries', 0],
        'negative backoff' => ['backoff', -1],
        'negative unique duration' => ['unique_for', -1],
        'finite unique duration equal to timeout' => ['unique_for', 3600],
        'finite unique duration below timeout' => ['unique_for', 3599],
    ]);

    it('rejects invalid queue names during direct construction', function (mixed $name) {
        config(['mysql-optimizer.queue.name' => $name]);

        expect(fn () => new OptimizeTablesJob)
            ->toThrow(InvalidQueueConfigurationException::class, 'queue name');
    })->with([
        'empty string' => [''],
        'array' => [[]],
        'object' => [(object) ['name' => 'mysql-optimizer']],
    ]);

    it('rejects invalid queue connections during direct construction', function (mixed $connection) {
        config(['mysql-optimizer.queue.connection' => $connection]);

        expect(fn () => new OptimizeTablesJob)
            ->toThrow(InvalidQueueConfigurationException::class, 'queue connection');
    })->with([
        'empty string' => [''],
        'array' => [[]],
        'object' => [(object) ['connection' => 'optimizer']],
    ]);

    it('rejects a finite unique duration below the timeout safety margin', function () {
        config([
            'mysql-optimizer.queue.timeout' => 100,
            'mysql-optimizer.queue.unique_for' => 109,
        ]);

        expect(fn () => new OptimizeTablesJob)
            ->toThrow(InvalidQueueConfigurationException::class, 'queue wait');
    });

    it('accepts a finite unique duration at the timeout safety margin', function () {
        config([
            'mysql-optimizer.queue.timeout' => 100,
            'mysql-optimizer.queue.unique_for' => 110,
        ]);

        expect((new OptimizeTablesJob)->uniqueFor)->toBe(110);
    });

    it('validates actual reserved connection before logging or database work', function () {
        config([
            'mysql-optimizer.queue.timeout' => 100,
            'queue.connections.configured' => ['driver' => 'sqs'],
            'queue.connections.actual' => ['driver' => 'redis', 'retry_after' => 100],
        ]);

        Log::spy();
        $builder = Mockery::mock(Builder::class);
        $builder->shouldNotReceive('newQuery');
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('getConnectionName')->once()->andReturn('actual');

        $job = (new OptimizeTablesJob)->onConnection('configured');
        $job->setJob($queueJob);

        expect(fn () => $job->handle($builder))
            ->toThrow(InvalidQueueConfigurationException::class);
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('error');
    });

    it('dispatches one child and does not log misleading completion on a safe connection', function () {
        config([
            'mysql-optimizer.database' => 'test_db',
            'mysql-optimizer.queue.per_table' => true,
            'mysql-optimizer.queue.connection' => 'optimizer',
            'mysql-optimizer.queue.name' => 'mysql-optimizer',
            'mysql-optimizer.queue.timeout' => 100,
            'queue.connections.optimizer' => ['driver' => 'redis', 'retry_after' => 110],
        ]);

        Queue::fake();
        Log::spy();
        $builder = Mockery::mock(Builder::class);
        $query = Mockery::mock(Builder::class);
        $builder->shouldReceive('newQuery')->once()->andReturn($query);
        $query->shouldReceive('selectRaw')->once()->with('TABLE_NAME')->andReturnSelf();
        $query->shouldReceive('fromRaw')->once()->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
        $query->shouldReceive('whereRaw')->once()->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
        $query->shouldReceive('whereRaw')->once()->with('TABLE_NAME IN (?)', ['users'])->andReturnSelf();
        $query->shouldReceive('get')->once()->andReturn(collect([
            (object) ['TABLE_NAME' => 'users'],
        ]));

        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('getConnectionName')->once()->andReturn('optimizer');
        $queueJob->shouldReceive('getQueue')->once()->andReturn('mysql-optimizer');
        $queueJob->shouldReceive('getJobId')->times(2)->andReturn('job-id');
        $queueJob->shouldReceive('attempts')->times(2)->andReturn(1);
        $queueJob->shouldReceive('timeout')->times(2)->andReturn(100);

        $job = new OptimizeTablesJob(null, ['users']);
        $job->setJob($queueJob);
        $job->handle($builder);

        Queue::assertPushed(OptimizeTableJob::class, 1);
        Log::shouldHaveReceived('info')
            ->with('MySQLOptimizer: Optimization orchestration started', Mockery::type('array'))
            ->once();
        Log::shouldHaveReceived('info')
            ->with('MySQLOptimizer: Optimization jobs dispatched', Mockery::type('array'))
            ->once();
        Log::shouldNotHaveReceived('info', ['MySQLOptimizer: Optimization job completed ✅', Mockery::any()]);
        Log::shouldNotHaveReceived('error');
    });
});
