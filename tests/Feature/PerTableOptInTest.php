<?php

use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use MySQLOptimizer\Actions\OptimizeTablesAction;
use MySQLOptimizer\Actions\ResolveOptimizationTargetsAction;
use MySQLOptimizer\Exceptions\InvalidQueueConfigurationException;
use MySQLOptimizer\Exceptions\TableNotFoundException;
use MySQLOptimizer\Jobs\OptimizeTableJob;
use MySQLOptimizer\Jobs\OptimizeTablesJob;

it('defaults to monolithic mode and executes tables sequentially without children', function () {
    config(['mysql-optimizer.database' => 'test_db']);
    Queue::fake();
    Log::spy();

    $builder = Mockery::mock(Builder::class);
    $query = Mockery::mock(Builder::class);
    $connection = Mockery::mock(Connection::class);
    $builder->shouldReceive('newQuery')->once()->andReturn($query);
    $query->shouldReceive('selectRaw')->once()->with('TABLE_NAME')->andReturnSelf();
    $query->shouldReceive('fromRaw')->once()->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
    $query->shouldReceive('whereRaw')->once()->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
    $query->shouldReceive('get')->once()->andReturn(collect([
        (object) ['TABLE_NAME' => 'users'],
        (object) ['TABLE_NAME' => 'posts'],
    ]));
    $builder->shouldReceive('getConnection')->twice()->andReturn($connection);
    $connection->shouldReceive('select')->once()
        ->with('OPTIMIZE TABLE `test_db`.`users`')
        ->andReturn([(object) ['Msg_text' => 'OK']]);
    $connection->shouldReceive('select')->once()
        ->with('OPTIMIZE TABLE `test_db`.`posts`')
        ->andReturn([(object) ['Msg_text' => 'OK']]);

    $job = new OptimizeTablesJob;
    $job->handle($builder);

    expect($job->perTable)->toBeFalse();
    Queue::assertNothingPushed();
    Log::shouldHaveReceived('info')
        ->with('MySQLOptimizer: Optimization job started ▶️', Mockery::type('array'))
        ->once();
    Log::shouldHaveReceived('info')
        ->with('MySQLOptimizer: Optimization job completed ✅', Mockery::on(
            fn (array $context) => $context['total_tables'] === 2
                && $context['successful'] === 2
                && $context['failed'] === 0
        ))
        ->once();
    Log::shouldNotHaveReceived('info', ['MySQLOptimizer: Optimization jobs dispatched', Mockery::any()]);
});

it('rejects per table mode without explicit dedicated routing', function (?string $connection, ?string $queue) {
    config([
        'mysql-optimizer.queue.per_table' => true,
        'mysql-optimizer.queue.connection' => $connection,
        'mysql-optimizer.queue.name' => $queue,
    ]);

    expect(fn () => new OptimizeTablesJob)
        ->toThrow(InvalidQueueConfigurationException::class, 'dedicated one-worker queue');
})->with([
    'both inherited' => [null, null],
    'connection inherited' => [null, 'mysql-optimizer'],
    'queue inherited' => ['optimizer', null],
]);

it('captures per table mode and forces orchestrator to one try', function () {
    config(['mysql-optimizer.queue' => [
        'per_table' => true,
        'connection' => 'optimizer',
        'name' => 'mysql-optimizer',
        'timeout' => 100,
        'tries' => 3,
        'backoff' => 60,
        'unique_for' => 0,
    ]]);

    $job = new OptimizeTablesJob;

    expect($job->perTable)->toBeTrue()
        ->and($job->tries)->toBe(1);
});

it('fans out canonical table names when per table mode is enabled', function () {
    config(['mysql-optimizer.queue' => [
        'per_table' => true,
        'connection' => 'optimizer',
        'name' => 'mysql-optimizer',
        'timeout' => 100,
        'tries' => 3,
        'backoff' => 60,
        'unique_for' => 0,
    ]]);
    config(['queue.connections.optimizer' => ['driver' => 'redis', 'retry_after' => 110]]);
    config(['mysql-optimizer.database' => 'test_db']);
    Queue::fake();

    $builder = Mockery::mock(Builder::class);
    $query = Mockery::mock(Builder::class);
    $builder->shouldReceive('newQuery')->once()->andReturn($query);
    $query->shouldReceive('selectRaw')->once()->with('TABLE_NAME')->andReturnSelf();
    $query->shouldReceive('fromRaw')->once()->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
    $query->shouldReceive('whereRaw')->once()->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
    $query->shouldReceive('whereRaw')->once()->with('TABLE_NAME IN (?)', ['users'])->andReturnSelf();
    $query->shouldReceive('get')->once()->andReturn(collect([
        (object) ['TABLE_NAME' => 'Users'],
    ]));

    $reservedJob = Mockery::mock(QueueJob::class);
    $reservedJob->shouldReceive('getConnectionName')->once()->andReturn('optimizer');
    $reservedJob->shouldReceive('getQueue')->once()->andReturn('mysql-optimizer');

    $job = new OptimizeTablesJob(null, ['users'], false);
    $job->setJob($reservedJob);
    $job->handle($builder);

    Queue::assertPushed(OptimizeTableJob::class, fn (OptimizeTableJob $child) => $child->database === 'test_db'
        && $child->table === 'Users'
        && $child->tries === 3);
});

it('resolves requested table aliases to canonical names', function () {
    config(['mysql-optimizer.database' => 'test_db']);
    $builder = Mockery::mock(Builder::class);
    $query = Mockery::mock(Builder::class);
    $builder->shouldReceive('newQuery')->once()->andReturn($query);
    $query->shouldReceive('selectRaw')->once()->with('TABLE_NAME')->andReturnSelf();
    $query->shouldReceive('fromRaw')->once()->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
    $query->shouldReceive('whereRaw')->once()->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
    $query->shouldReceive('whereRaw')->once()->with('TABLE_NAME IN (?)', ['users'])->andReturnSelf();
    $query->shouldReceive('get')->once()->andReturn(collect([
        (object) ['TABLE_NAME' => 'Users'],
    ]));

    $targets = (new ResolveOptimizationTargetsAction($builder))->execute(null, ['users']);

    expect($targets->tables->all())->toBe(['Users']);
});

it('rejects duplicate requested table identities', function () {
    config(['mysql-optimizer.database' => 'test_db']);
    $builder = Mockery::mock(Builder::class);
    $query = Mockery::mock(Builder::class);
    $builder->shouldReceive('newQuery')->once()->andReturn($query);
    $query->shouldReceive('selectRaw')->once()->with('TABLE_NAME')->andReturnSelf();
    $query->shouldReceive('fromRaw')->once()->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
    $query->shouldReceive('whereRaw')->once()->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
    $query->shouldReceive('whereRaw')->once()->with('TABLE_NAME IN (?,?)', ['Users', 'users'])->andReturnSelf();
    $query->shouldReceive('get')->once()->andReturn(collect([
        (object) ['TABLE_NAME' => 'Users'],
    ]));

    expect(fn () => (new ResolveOptimizationTargetsAction($builder))
        ->execute(null, ['Users', 'users']))
        ->toThrow(TableNotFoundException::class);
});

it('resolves an explicit database alias to its canonical schema name', function () {
    $builder = Mockery::mock(Builder::class);
    $schemaQuery = Mockery::mock(Builder::class);
    $tableQuery = Mockery::mock(Builder::class);
    $builder->shouldReceive('newQuery')->twice()
        ->andReturn($schemaQuery, $tableQuery);
    $schemaQuery->shouldReceive('selectRaw')->once()->with('SCHEMA_NAME')->andReturnSelf();
    $schemaQuery->shouldReceive('fromRaw')->once()->with('INFORMATION_SCHEMA.SCHEMATA')->andReturnSelf();
    $schemaQuery->shouldReceive('whereRaw')->once()->with('SCHEMA_NAME = ?', ['test_DB'])->andReturnSelf();
    $schemaQuery->shouldReceive('value')->once()->with('SCHEMA_NAME')->andReturn('Test_DB');
    $tableQuery->shouldReceive('selectRaw')->once()->with('TABLE_NAME')->andReturnSelf();
    $tableQuery->shouldReceive('fromRaw')->once()->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
    $tableQuery->shouldReceive('whereRaw')->once()->with('TABLE_SCHEMA = ?', ['Test_DB'])->andReturnSelf();
    $tableQuery->shouldReceive('get')->once()->andReturn(collect([
        (object) ['TABLE_NAME' => 'Users'],
    ]));

    $targets = (new ResolveOptimizationTargetsAction($builder))->execute('test_DB');

    expect($targets->database)->toBe('Test_DB')
        ->and($targets->tables->all())->toBe(['Users']);
});

it('uses one orchestrator unique id for equivalent configured database targets', function () {
    config(['mysql-optimizer.database' => 'Main_DB']);

    $implicit = new OptimizeTablesJob;
    $default = new OptimizeTablesJob('default');
    $explicit = new OptimizeTablesJob('Main_DB');
    $caseDistinct = new OptimizeTablesJob('main_db');

    expect($implicit->uniqueId())->toBe($default->uniqueId())
        ->and($implicit->uniqueId())->toBe($explicit->uniqueId())
        ->and($implicit->uniqueId())->not->toBe($caseDistinct->uniqueId());
});

it('runs a legacy serialized monolithic job without initialized run id', function () {
    config([
        'mysql-optimizer.database' => 'test_db',
        'queue.default' => 'legacy',
        'queue.connections.legacy' => ['driver' => 'redis', 'retry_after' => 3610],
    ]);
    Queue::fake();

    $legacy = (new ReflectionClass(OptimizeTablesJob::class))->newInstanceWithoutConstructor();
    $legacy->database = null;
    $legacy->tables = ['users'];
    $legacy->shouldLog = false;
    $legacy->tries = 3;
    $legacy->timeout = 3600;
    $legacy->uniqueFor = 3600;
    $legacy->backoff = 3600;

    expect($legacy->runId)->toBeNull();

    $builder = Mockery::mock(Builder::class);
    $query = Mockery::mock(Builder::class);
    $connection = Mockery::mock(Connection::class);
    $builder->shouldReceive('newQuery')->once()->andReturn($query);
    $query->shouldReceive('selectRaw')->once()->with('TABLE_NAME')->andReturnSelf();
    $query->shouldReceive('fromRaw')->once()->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
    $query->shouldReceive('whereRaw')->once()->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
    $query->shouldReceive('whereRaw')->once()->with('TABLE_NAME IN (?)', ['users'])->andReturnSelf();
    $query->shouldReceive('get')->once()->andReturn(collect([
        (object) ['TABLE_NAME' => 'users'],
    ]));
    $builder->shouldReceive('getConnection')->once()->andReturn($connection);
    $connection->shouldReceive('select')->once()
        ->with('OPTIMIZE TABLE `test_db`.`users`')
        ->andReturn([(object) ['Msg_text' => 'OK']]);

    $legacy->handle($builder);

    expect($legacy->runId)->toBeString()->not->toBeEmpty()
        ->and($legacy->tries)->toBe(3)
        ->and($legacy->timeout)->toBe(3600)
        ->and($legacy->uniqueFor)->toBe(0)
        ->and($legacy->backoff)->toBe(3600);
    Queue::assertNothingPushed();
});

it('logs permanent failure for a legacy serialized job without initialized run id', function () {
    Log::spy();
    $legacy = (new ReflectionClass(OptimizeTablesJob::class))->newInstanceWithoutConstructor();
    $legacy->database = null;
    $legacy->tables = ['users'];
    $legacy->shouldLog = true;

    $legacy->failed(new RuntimeException('legacy failure'));

    expect($legacy->runId)->toBeString()->not->toBeEmpty();
    Log::shouldHaveReceived('error')->with(
        'MySQLOptimizer: Optimization job permanently failed ❌',
        Mockery::on(fn (array $context) => is_string($context['run_id'])
            && $context['run_id'] !== ''
            && $context['error'] === 'legacy failure')
    )->once();
});

it('preserves requested table order while resolving canonical names', function () {
    config(['mysql-optimizer.database' => 'test_db']);
    $builder = Mockery::mock(Builder::class);
    $query = Mockery::mock(Builder::class);
    $builder->shouldReceive('newQuery')->once()->andReturn($query);
    $query->shouldReceive('selectRaw')->once()->with('TABLE_NAME')->andReturnSelf();
    $query->shouldReceive('fromRaw')->once()->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
    $query->shouldReceive('whereRaw')->once()->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
    $query->shouldReceive('whereRaw')->once()
        ->with('TABLE_NAME IN (?,?)', ['posts', 'users'])->andReturnSelf();
    $query->shouldReceive('get')->once()->andReturn(collect([
        (object) ['TABLE_NAME' => 'Users'],
        (object) ['TABLE_NAME' => 'Posts'],
    ]));

    $targets = (new ResolveOptimizationTargetsAction($builder))
        ->execute(null, ['posts', 'users']);

    expect($targets->tables->all())->toBe(['Posts', 'Users']);
});

it('preserves exact case-sensitive table identities', function () {
    config(['mysql-optimizer.database' => 'test_db']);
    $builder = Mockery::mock(Builder::class);
    $query = Mockery::mock(Builder::class);
    $builder->shouldReceive('newQuery')->once()->andReturn($query);
    $query->shouldReceive('selectRaw')->once()->with('TABLE_NAME')->andReturnSelf();
    $query->shouldReceive('fromRaw')->once()->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
    $query->shouldReceive('whereRaw')->once()->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
    $query->shouldReceive('whereRaw')->once()
        ->with('TABLE_NAME IN (?,?)', ['users', 'Users'])->andReturnSelf();
    $query->shouldReceive('get')->once()->andReturn(collect([
        (object) ['TABLE_NAME' => 'Users'],
        (object) ['TABLE_NAME' => 'users'],
    ]));

    $targets = (new ResolveOptimizationTargetsAction($builder))
        ->execute(null, ['users', 'Users']);

    expect($targets->tables->all())->toBe(['users', 'Users']);
});

it('preserves requested order in synchronous results and progress', function () {
    config(['mysql-optimizer.database' => 'test_db']);
    $builder = Mockery::mock(Builder::class);
    $query = Mockery::mock(Builder::class);
    $connection = Mockery::mock(Connection::class);
    $builder->shouldReceive('newQuery')->once()->andReturn($query);
    $query->shouldReceive('selectRaw')->once()->with('TABLE_NAME')->andReturnSelf();
    $query->shouldReceive('fromRaw')->once()->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
    $query->shouldReceive('whereRaw')->once()->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
    $query->shouldReceive('whereRaw')->once()
        ->with('TABLE_NAME IN (?,?)', ['posts', 'users'])->andReturnSelf();
    $query->shouldReceive('get')->once()->andReturn(collect([
        (object) ['TABLE_NAME' => 'Users'],
        (object) ['TABLE_NAME' => 'Posts'],
    ]));
    $builder->shouldReceive('getConnection')->twice()->andReturn($connection);
    $connection->shouldReceive('select')->once()
        ->with('OPTIMIZE TABLE `test_db`.`Posts`')
        ->andReturn([(object) ['Msg_text' => 'OK']]);
    $connection->shouldReceive('select')->once()
        ->with('OPTIMIZE TABLE `test_db`.`Users`')
        ->andReturn([(object) ['Msg_text' => 'OK']]);
    $progress = [];

    $results = (new OptimizeTablesAction($builder))->execute(
        null,
        ['posts', 'users'],
        function (string $table) use (&$progress): void {
            $progress[] = $table;
        },
    );

    expect($results->pluck('table')->all())->toBe(['Posts', 'Users'])
        ->and($progress)->toBe(['Posts', 'Users']);
});

it('preserves requested order when dispatching per table children', function () {
    config([
        'mysql-optimizer.database' => 'test_db',
        'mysql-optimizer.queue' => [
            'per_table' => true,
            'connection' => 'optimizer',
            'name' => 'mysql-optimizer',
            'timeout' => 100,
            'tries' => 1,
            'backoff' => 60,
            'unique_for' => 0,
        ],
        'queue.connections.optimizer' => ['driver' => 'redis', 'retry_after' => 110],
    ]);
    Queue::fake();

    $builder = Mockery::mock(Builder::class);
    $query = Mockery::mock(Builder::class);
    $builder->shouldReceive('newQuery')->once()->andReturn($query);
    $query->shouldReceive('selectRaw')->once()->with('TABLE_NAME')->andReturnSelf();
    $query->shouldReceive('fromRaw')->once()->with('INFORMATION_SCHEMA.TABLES')->andReturnSelf();
    $query->shouldReceive('whereRaw')->once()->with('TABLE_SCHEMA = ?', ['test_db'])->andReturnSelf();
    $query->shouldReceive('whereRaw')->once()
        ->with('TABLE_NAME IN (?,?)', ['posts', 'users'])->andReturnSelf();
    $query->shouldReceive('get')->once()->andReturn(collect([
        (object) ['TABLE_NAME' => 'Users'],
        (object) ['TABLE_NAME' => 'Posts'],
    ]));
    $reservedJob = Mockery::mock(QueueJob::class);
    $reservedJob->shouldReceive('getConnectionName')->once()->andReturn('optimizer');
    $reservedJob->shouldReceive('getQueue')->once()->andReturn('mysql-optimizer');

    $job = new OptimizeTablesJob(null, ['posts', 'users'], false);
    $job->setJob($reservedJob);
    $job->handle($builder);

    expect(Queue::pushed(OptimizeTableJob::class)->pluck('table')->all())
        ->toBe(['Posts', 'Users']);
});
