<?php

use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use MySQLOptimizer\Actions\OptimizeTableAction;
use MySQLOptimizer\Actions\ResolveOptimizationTargetsAction;
use MySQLOptimizer\Exceptions\InvalidQueueConfigurationException;
use MySQLOptimizer\Jobs\OptimizeTableJob;
use MySQLOptimizer\Jobs\OptimizeTablesJob;
use MySQLOptimizer\ValueObjects\OptimizationTargets;

it('resolves optimization targets with database and tables', function () {
    config(['mysql-optimizer.database' => 'test_db']);

    $builder = Mockery::mock(Builder::class);
    $query = Mockery::mock(Builder::class);
    $builder->shouldReceive('newQuery')->once()->andReturn($query);
    $query->shouldReceive('selectRaw')->once()->with('TABLE_NAME')->andReturnSelf();
    $query->shouldReceive('fromRaw')->once()->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
    $query->shouldReceive('whereRaw')->once()->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
    $query->shouldReceive('get')->once()->andReturn(collect([
        (object) ['TABLE_NAME' => 'users'],
        (object) ['TABLE_NAME' => 'posts'],
    ]));

    $targets = (new ResolveOptimizationTargetsAction($builder))->execute();

    expect($targets)->toBeInstanceOf(OptimizationTargets::class)
        ->and($targets->database)->toBe('test_db')
        ->and($targets->tables->all())->toBe(['users', 'posts']);
});

it('optimizes one qualified table with escaped identifiers', function () {
    $builder = Mockery::mock(Builder::class);
    $connection = Mockery::mock(Connection::class);
    $builder->shouldReceive('getConnection')->once()->andReturn($connection);
    $connection->shouldReceive('select')->once()
        ->with('OPTIMIZE TABLE `archive``db`.`user``events`')
        ->andReturn([(object) ['Msg_text' => 'OK']]);

    $result = (new OptimizeTableAction($builder))->execute('archive`db', 'user`events');

    expect($result)->toBeTrue();
});

it('orchestrates one child per resolved table on actual route with one run id', function () {
    config([
        'mysql-optimizer.database' => 'requested_db',
        'mysql-optimizer.queue.per_table' => true,
        'mysql-optimizer.queue.connection' => 'actual',
        'mysql-optimizer.queue.name' => 'optimizer-priority',
        'mysql-optimizer.queue.timeout' => 100,
        'queue.connections.actual' => ['driver' => 'redis', 'retry_after' => 110],
    ]);

    Queue::fake();
    Log::spy();

    $builder = Mockery::mock(Builder::class);
    $query = Mockery::mock(Builder::class);
    $builder->shouldReceive('newQuery')->once()->andReturn($query);
    $query->shouldReceive('selectRaw')->once()->with('TABLE_NAME')->andReturnSelf();
    $query->shouldReceive('fromRaw')->once()->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
    $query->shouldReceive('whereRaw')->once()->with('TABLE_SCHEMA = ?', ['requested_db'])->andReturnSelf();
    $query->shouldReceive('whereRaw')->once()
        ->with('TABLE_NAME IN (?,?)', ['users', 'posts'])->andReturnSelf();
    $query->shouldReceive('get')->once()->andReturn(collect([
        (object) ['TABLE_NAME' => 'users'],
        (object) ['TABLE_NAME' => 'posts'],
    ]));

    $reservedJob = Mockery::mock(QueueJob::class);
    $reservedJob->shouldReceive('getConnectionName')->once()->andReturn('actual');
    $reservedJob->shouldReceive('getQueue')->once()->andReturn('optimizer-priority');
    $reservedJob->shouldReceive('getJobId')->andReturn('orchestrator-id');
    $reservedJob->shouldReceive('attempts')->andReturn(1);
    $reservedJob->shouldReceive('timeout')->andReturn(100);

    $job = new OptimizeTablesJob(null, ['users', 'posts']);
    $runId = $job->runId;
    $job->setJob($reservedJob);
    $job->handle($builder);

    Queue::assertPushed(OptimizeTableJob::class, 2);
    Queue::assertPushed(OptimizeTableJob::class, function (OptimizeTableJob $child) use ($runId) {
        return $child->database === 'requested_db'
            && in_array($child->table, ['users', 'posts'], true)
            && $child->runId === $runId
            && $child->connection === 'actual'
            && $child->queue === 'optimizer-priority';
    });
    Log::shouldHaveReceived('info')
        ->with('MySQLOptimizer: Optimization jobs dispatched', Mockery::on(
            fn (array $context) => $context['run_id'] === $runId && $context['table_count'] === 2
        ))
        ->once();
    Log::shouldNotHaveReceived('info', ['MySQLOptimizer: Optimization job completed ✅', Mockery::any()]);
});

it('child unique id is stable for the exact database and table pair', function () {
    $first = new OptimizeTableJob('main_db', 'users', true, 'run-one');
    $second = new OptimizeTableJob('main_db', 'users', true, 'run-two');

    expect($first->uniqueId())->toBe($second->uniqueId())
        ->and($first->uniqueId())->toStartWith('optimize-table:')
        ->and(strlen($first->uniqueId()))->toBe(strlen('optimize-table:') + 64);
});

it('child unique id distinguishes case whitespace and delimiter pairs', function (
    array $firstPair,
    array $secondPair,
) {
    $first = new OptimizeTableJob($firstPair[0], $firstPair[1], true, 'run-one');
    $second = new OptimizeTableJob($secondPair[0], $secondPair[1], true, 'run-two');

    expect($first->uniqueId())->not->toBe($second->uniqueId());
})->with([
    'database case' => [['Main_DB', 'users'], ['main_db', 'users']],
    'table case' => [['main_db', 'Users'], ['main_db', 'users']],
    'database whitespace' => [[' main_db', 'users'], ['main_db', 'users']],
    'table whitespace' => [['main_db', 'users '], ['main_db', 'users']],
    'delimiter collision' => [['one:two', 'three'], ['one', 'two:three']],
]);

it('child validates before DDL and executes only its table', function () {
    config([
        'mysql-optimizer.queue.timeout' => 100,
        'queue.connections.actual' => ['driver' => 'redis', 'retry_after' => 110],
    ]);

    Log::spy();
    $action = Mockery::mock(OptimizeTableAction::class);
    $action->shouldReceive('execute')->once()->with('test_db', 'users')->andReturn(true);

    $reservedJob = Mockery::mock(QueueJob::class);
    $reservedJob->shouldReceive('getConnectionName')->once()->andReturn('actual');
    $reservedJob->shouldReceive('getJobId')->andReturn('child-id');
    $reservedJob->shouldReceive('attempts')->andReturn(1);
    $reservedJob->shouldReceive('timeout')->andReturn(100);

    $job = new OptimizeTableJob('test_db', 'users', true, 'run-id');
    $job->setJob($reservedJob);
    $job->handle($action);

    Log::shouldHaveReceived('info')->with(
        'MySQLOptimizer: Table optimization completed',
        Mockery::on(fn (array $context) => $context['run_id'] === 'run-id'
            && $context['database'] === 'test_db'
            && $context['table'] === 'users'
            && $context['attempt'] === 1
            && isset($context['duration_ms']))
    )->once();
});

it('child uses configured runtime properties and routing', function () {
    config(['mysql-optimizer.queue' => [
        'connection' => 'optimizer',
        'name' => 'mysql-optimizer',
        'timeout' => 5400,
        'tries' => 1,
        'backoff' => 120,
        'unique_for' => 6000,
    ]]);

    $job = new OptimizeTableJob('test_db', 'users', true, 'run-id');

    expect($job->timeout)->toBe(5400)
        ->and($job->tries)->toBe(1)
        ->and($job->backoff)->toBe(120)
        ->and($job->uniqueFor)->toBe(6000)
        ->and($job->connection)->toBe('optimizer')
        ->and($job->queue)->toBe('mysql-optimizer');
});

it('child rejects unsafe actual configuration before DDL or logging', function () {
    config([
        'mysql-optimizer.queue.timeout' => 100,
        'queue.connections.actual' => ['driver' => 'redis', 'retry_after' => 100],
    ]);

    Log::spy();
    $action = Mockery::mock(OptimizeTableAction::class);
    $action->shouldNotReceive('execute');
    $reservedJob = Mockery::mock(QueueJob::class);
    $reservedJob->shouldReceive('getConnectionName')->once()->andReturn('actual');

    $job = new OptimizeTableJob('test_db', 'users', true, 'run-id');
    $job->setJob($reservedJob);

    expect(fn () => $job->handle($action))
        ->toThrow(InvalidQueueConfigurationException::class);
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('error');
});

it('child failure cannot dispatch or replay other tables', function () {
    config([
        'mysql-optimizer.queue.timeout' => 100,
        'queue.connections.actual' => ['driver' => 'redis', 'retry_after' => 110],
    ]);

    Queue::fake();
    $action = Mockery::mock(OptimizeTableAction::class);
    $action->shouldReceive('execute')->once()->with('test_db', 'users')
        ->andThrow(new RuntimeException('optimization failed'));
    $reservedJob = Mockery::mock(QueueJob::class);
    $reservedJob->shouldReceive('getConnectionName')->once()->andReturn('actual');

    $job = new OptimizeTableJob('test_db', 'users', false, 'run-id');
    $job->setJob($reservedJob);

    expect(fn () => $job->handle($action))->toThrow(RuntimeException::class);
    Queue::assertNothingPushed();
});

it('child logs correlated attempt failure with duration and rethrows', function () {
    config([
        'mysql-optimizer.queue.timeout' => 100,
        'queue.connections.actual' => ['driver' => 'redis', 'retry_after' => 110],
    ]);

    Log::spy();
    $action = Mockery::mock(OptimizeTableAction::class);
    $action->shouldReceive('execute')->once()->with('test_db', 'users')
        ->andThrow(new RuntimeException('optimization failed'));
    $reservedJob = Mockery::mock(QueueJob::class);
    $reservedJob->shouldReceive('getConnectionName')->once()->andReturn('actual');
    $reservedJob->shouldReceive('getJobId')->times(2)->andReturn('child-id');
    $reservedJob->shouldReceive('attempts')->times(4)->andReturn(2);
    $reservedJob->shouldReceive('timeout')->times(2)->andReturn(100);

    $job = new OptimizeTableJob('test_db', 'users', true, 'run-id');
    $job->setJob($reservedJob);

    expect(fn () => $job->handle($action))
        ->toThrow(RuntimeException::class, 'optimization failed');
    Log::shouldHaveReceived('error')->with(
        'MySQLOptimizer: Table optimization attempt failed',
        Mockery::on(fn (array $context) => $context['run_id'] === 'run-id'
            && $context['database'] === 'test_db'
            && $context['table'] === 'users'
            && $context['attempt'] === 2
            && $context['job']['id'] === 'child-id'
            && $context['error'] === 'optimization failed'
            && isset($context['duration_ms']))
    )->once();
});

it('child permanently failed hook logs correlation', function () {
    Log::spy();
    $reservedJob = Mockery::mock(QueueJob::class);
    $reservedJob->shouldReceive('getJobId')->once()->andReturn('child-id');
    $reservedJob->shouldReceive('attempts')->times(2)->andReturn(3);
    $reservedJob->shouldReceive('timeout')->once()->andReturn(100);

    $job = new OptimizeTableJob('test_db', 'users', true, 'run-id');
    $job->setJob($reservedJob);
    $job->failed(new RuntimeException('permanent failure'));

    Log::shouldHaveReceived('error')->with(
        'MySQLOptimizer: Table optimization permanently failed',
        Mockery::on(fn (array $context) => $context['run_id'] === 'run-id'
            && $context['database'] === 'test_db'
            && $context['table'] === 'users'
            && $context['attempt'] === 3
            && $context['job']['id'] === 'child-id'
            && $context['error'] === 'permanent failure')
    )->once();
});
