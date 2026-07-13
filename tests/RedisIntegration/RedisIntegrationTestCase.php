<?php

namespace Tests\RedisIntegration;

use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\WorkerOptions;
use RuntimeException;
use Tests\TestCase;
use Throwable;

abstract class RedisIntegrationTestCase extends TestCase
{
    /** @var array<int, array{0: RedisQueue, 1: string}> */
    private array $testQueues = [];

    /** @var array<int, string> */
    private array $testRedisKeys = [];

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.redis.client', 'phpredis');
        $app['config']->set('database.redis.optimizer_integration', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => (int) env('REDIS_DB', 0),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (env('REDIS_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set REDIS_INTEGRATION=1 to run real Redis integration tests.');
        }

        if (! extension_loaded('redis')) {
            throw new RuntimeException(
                'REDIS_INTEGRATION=1 requires the phpredis extension, but it is not loaded.'
            );
        }

        try {
            $this->app['redis']->connection('optimizer_integration')->ping();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'REDIS_INTEGRATION=1 requires a reachable Redis server: '.$exception->getMessage(),
                previous: $exception,
            );
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->testQueues as [$queue, $name]) {
            try {
                $queue->clear($name);
            } catch (Throwable) {
                // Preserve original test result when Redis becomes unavailable during cleanup.
            }
        }

        try {
            $redis = $this->app['redis']->connection('optimizer_integration');

            foreach ($this->testRedisKeys as $key) {
                $redis->del($key);
            }
        } catch (Throwable) {
            // Preserve original test result when Redis becomes unavailable during cleanup.
        }

        parent::tearDown();
    }

    /** @return array{0: RedisQueue, 1: string, 2: string} */
    protected function redisQueue(int $retryAfter, int $timeout): array
    {
        $suffix = bin2hex(random_bytes(8));
        $connection = 'optimizer_integration_'.$suffix;
        $queueName = 'mysql_optimizer_integration_'.$suffix;

        config([
            "queue.connections.{$connection}" => [
                'driver' => 'redis',
                'connection' => 'optimizer_integration',
                'queue' => $queueName,
                'retry_after' => $retryAfter,
                'block_for' => null,
                'after_commit' => false,
            ],
            'mysql-optimizer.queue' => [
                'per_table' => false,
                'connection' => $connection,
                'name' => $queueName,
                'timeout' => $timeout,
                'tries' => 1,
                'backoff' => 60,
                'unique_for' => 0,
            ],
        ]);

        /** @var RedisQueue $queue */
        $queue = $this->app['queue']->connection($connection);
        $this->testQueues[] = [$queue, $queueName];

        return [$queue, $connection, $queueName];
    }

    protected function redisTestKey(string $purpose): string
    {
        $key = 'mysql_optimizer_integration:'.$purpose.':'.bin2hex(random_bytes(8));
        $this->testRedisKeys[] = $key;

        return $key;
    }

    /** @return array<int, int> */
    protected function runConcurrentWorkers(
        string $connection,
        string $queue,
        string $barrierKey,
        int $workerCount,
    ): array {
        $pids = [];

        for ($worker = 0; $worker < $workerCount; $worker++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Unable to fork Redis integration worker process.');
            }

            if ($pid === 0) {
                $status = 0;

                try {
                    $this->app['redis']->purge('optimizer_integration');
                    $redis = $this->app['redis']->connection('optimizer_integration');
                    $redis->incr($barrierKey);
                    $deadline = microtime(true) + 5;

                    while ((int) $redis->get($barrierKey) < $workerCount) {
                        if (microtime(true) >= $deadline) {
                            throw new RuntimeException('Redis integration worker barrier timed out.');
                        }

                        usleep(10_000);
                    }

                    $options = new WorkerOptions('redis-integration', 0, 128, 2, 0, 1, true);
                    $this->app['queue.worker']->runNextJob($connection, $queue, $options);
                } catch (Throwable $exception) {
                    file_put_contents(
                        'php://stderr',
                        'Redis integration child worker failed: '.$exception->getMessage().PHP_EOL,
                    );
                    $status = 1;
                }

                exit($status);
            }

            $pids[] = $pid;
        }

        $statuses = [];

        foreach ($pids as $pid) {
            $waited = pcntl_waitpid($pid, $status);
            $statuses[] = $waited === $pid && pcntl_wifexited($status)
                ? pcntl_wexitstatus($status)
                : 255;
        }

        return $statuses;
    }
}
