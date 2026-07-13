<?php

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MySQLOptimizer\Support\QueueConfigurationValidator;

class RedisReservationProbeJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 1;

    public $backoff = 3600;

    public function handle(): void {}
}

class RedisWorkerProbeJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 2;

    public function __construct(
        public string $redisConnection,
        public string $eventsKey,
        public string $attemptsKey,
    ) {}

    public function handle(): void
    {
        $redis = app('redis')->connection($this->redisConnection);
        $redis->hincrby($this->eventsKey, 'started', 1);
        $redis->rpush($this->attemptsKey, (string) $this->job->attempts());

        usleep(750_000);

        $redis->hincrby($this->eventsKey, 'completed', 1);
    }

    public function failed(): void
    {
        app('redis')->connection($this->redisConnection)
            ->hincrby($this->eventsKey, 'failed', 1);
    }
}

it('rejects unsafe Redis configuration before the queued command pushes a payload', function () {
    [$queue, , $queueName] = $this->redisQueue(19, 10);

    expect($queue->size($queueName))->toBe(0);

    $this->artisan('db:optimize', ['--queued' => true])
        ->expectsOutputToContain('minimum safety margin [10 seconds]')
        ->assertFailed();

    expect($queue->size($queueName))->toBe(0);
});

it('re-reserves an expired Redis payload despite tries and backoff', function () {
    [$queue, , $queueName] = $this->redisQueue(2, 1);
    $queue->push(new RedisReservationProbeJob, '', $queueName);

    $firstReservation = $queue->pop($queueName);

    expect($firstReservation)->not->toBeNull()
        ->and($firstReservation->attempts())->toBe(1)
        ->and($firstReservation->maxTries())->toBe(3)
        ->and((int) $firstReservation->backoff())->toBe(3600);

    $deadline = microtime(true) + 4;
    $secondReservation = null;

    do {
        usleep(100_000);
        $secondReservation = $queue->pop($queueName);
    } while ($secondReservation === null && microtime(true) < $deadline);

    expect($secondReservation)->not->toBeNull()
        ->and($secondReservation->getJobId())->toBe($firstReservation->getJobId())
        ->and($secondReservation->attempts())->toBe(2);

    $secondReservation->delete();
});

it('does not re-reserve a payload before safe retry_after expires', function () {
    [$queue, $connection, $queueName] = $this->redisQueue(11, 1);

    expect(app(QueueConfigurationValidator::class)->validateConfigured())->toBe($connection);

    $queue->push(new RedisReservationProbeJob, '', $queueName);

    $firstReservation = $queue->pop($queueName);

    expect($firstReservation)->not->toBeNull()
        ->and($firstReservation->attempts())->toBe(1);

    usleep(1_500_000);

    expect($queue->pop($queueName))->toBeNull();

    $firstReservation->delete();
    expect($queue->size($queueName))->toBe(0);
});

it('executes one safe payload once across three concurrent Laravel workers', function () {
    if (! function_exists('pcntl_fork')) {
        throw new RuntimeException(
            'REDIS_INTEGRATION=1 requires pcntl for concurrent Redis worker tests.'
        );
    }

    [$queue, $connection, $queueName] = $this->redisQueue(12, 2);
    $eventsKey = $this->redisTestKey('worker_events');
    $attemptsKey = $this->redisTestKey('worker_attempts');
    $barrierKey = $this->redisTestKey('worker_barrier');

    expect(app(QueueConfigurationValidator::class)->validateConfigured())->toBe($connection);

    $queue->push(
        new RedisWorkerProbeJob('optimizer_integration', $eventsKey, $attemptsKey),
        '',
        $queueName,
    );

    $statuses = $this->runConcurrentWorkers($connection, $queueName, $barrierKey, 3);
    $redis = app('redis')->connection('optimizer_integration');
    $events = $redis->hgetall($eventsKey);

    expect($statuses)->toBe([0, 0, 0])
        ->and((int) ($events['started'] ?? 0))->toBe(1)
        ->and((int) ($events['completed'] ?? 0))->toBe(1)
        ->and((int) ($events['failed'] ?? 0))->toBe(0)
        ->and($redis->lrange($attemptsKey, 0, -1))->toBe(['1'])
        ->and($queue->size($queueName))->toBe(0);
});
