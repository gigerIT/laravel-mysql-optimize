<?php

namespace MySQLOptimizer\Support;

use Illuminate\Contracts\Config\Repository;
use MySQLOptimizer\Exceptions\InvalidQueueConfigurationException;

class QueueConfigurationValidator
{
    private const INSPECTABLE_DRIVERS = ['redis', 'database', 'beanstalkd'];

    private const MINIMUM_RETRY_AFTER_MARGIN_SECONDS = 10;

    public function __construct(protected Repository $config) {}

    public function validateConfigured(): string
    {
        $connection = $this->config->get('mysql-optimizer.queue.connection');
        $queue = $this->config->get('mysql-optimizer.queue.name');
        $this->validateQueueName($queue);
        $this->validatePerTableRouting(
            (bool) $this->config->get('mysql-optimizer.queue.per_table', false),
            $connection,
            $queue,
        );

        return $this->validate(
            $connection,
            $this->config->get('mysql-optimizer.queue.timeout', 3600),
            $this->config->get('mysql-optimizer.queue.tries', 1),
            $this->config->get('mysql-optimizer.queue.backoff', 3600),
            $this->config->get('mysql-optimizer.queue.unique_for', 0),
        );
    }

    public function validate(
        mixed $connection,
        mixed $timeout,
        mixed $tries = 1,
        mixed $backoff = 3600,
        mixed $uniqueFor = 0,
    ): string {
        $this->validateRuntime($timeout, $tries, $backoff, $uniqueFor);
        $this->validateConnectionName($connection);

        $connection = $connection ?? $this->config->get('queue.default');
        if (! is_string($connection) || $connection === '') {
            throw new InvalidQueueConfigurationException(
                'MySQLOptimizer queue connection must be a non-empty string.'
            );
        }

        $configuration = $this->config->get("queue.connections.{$connection}");
        if (! is_array($configuration) || ! isset($configuration['driver'])) {
            throw new InvalidQueueConfigurationException(
                "MySQLOptimizer queue connection [{$connection}] is not configured."
            );
        }

        $driver = $configuration['driver'];
        if (! is_string($driver) || $driver === '') {
            throw new InvalidQueueConfigurationException(
                "MySQLOptimizer queue connection [{$connection}] has no valid driver."
            );
        }

        if ($driver === 'sync' || ! in_array($driver, self::INSPECTABLE_DRIVERS, true)) {
            return $connection;
        }

        $retryAfter = $configuration['retry_after'] ?? 60;
        if (! is_int($retryAfter) || $retryAfter <= 0) {
            throw new InvalidQueueConfigurationException(
                "MySQLOptimizer queue connection [{$connection}] using driver [{$driver}] must have a positive integer retry_after."
            );
        }

        if ($retryAfter < $timeout + self::MINIMUM_RETRY_AFTER_MARGIN_SECONDS) {
            throw new InvalidQueueConfigurationException(
                "Unsafe MySQLOptimizer queue connection [{$connection}] using driver [{$driver}]: ".
                "job timeout [{$timeout}], retry_after [{$retryAfter}]. Required ordering: ".
                'job timeout + safety margin <= queue retry_after, with minimum safety margin ['.
                self::MINIMUM_RETRY_AFTER_MARGIN_SECONDS.' seconds]. '.
                'When Horizon processes this queue, set its supervisor timeout greater than the job timeout '.
                'and less than retry_after. Use a dedicated optimizer queue with a safe timeout margin.'
            );
        }

        return $connection;
    }

    public function validateQueueName(mixed $queue): ?string
    {
        if ($queue !== null && (! is_string($queue) || $queue === '')) {
            throw new InvalidQueueConfigurationException(
                'MySQLOptimizer queue name must be null or a non-empty string.'
            );
        }

        return $queue;
    }

    public function validateConnectionName(mixed $connection): ?string
    {
        if ($connection !== null && (! is_string($connection) || $connection === '')) {
            throw new InvalidQueueConfigurationException(
                'MySQLOptimizer queue connection must be null or a non-empty string.'
            );
        }

        return $connection;
    }

    public function validatePerTableRouting(bool $perTable, mixed $connection, mixed $queue): void
    {
        if (! $perTable) {
            return;
        }

        if (! is_string($connection) || $connection === '' || ! is_string($queue) || $queue === '') {
            throw new InvalidQueueConfigurationException(
                'MySQLOptimizer per-table mode requires an explicit non-empty queue connection and queue name. '.
                'Configure a dedicated one-worker queue for table optimization.'
            );
        }
    }

    public function validateRuntime(
        mixed $timeout,
        mixed $tries,
        mixed $backoff,
        mixed $uniqueFor,
    ): void {
        $this->positiveInteger('timeout', $timeout);
        $this->positiveInteger('tries', $tries);
        $this->nonNegativeInteger('backoff', $backoff);
        $this->nonNegativeInteger('unique_for', $uniqueFor);

        if ($uniqueFor !== 0 && $uniqueFor < $timeout + self::MINIMUM_RETRY_AFTER_MARGIN_SECONDS) {
            throw new InvalidQueueConfigurationException(
                "MySQLOptimizer queue unique_for [{$uniqueFor}] must be 0 or at least timeout [{$timeout}] plus ".
                'the minimum safety margin ['.self::MINIMUM_RETRY_AFTER_MARGIN_SECONDS.
                ' seconds]. Configure additional duration to cover queue wait time.'
            );
        }
    }

    private function positiveInteger(string $name, mixed $value): void
    {
        if (! is_int($value) || $value <= 0) {
            throw new InvalidQueueConfigurationException(
                "MySQLOptimizer queue {$name} must be a positive integer."
            );
        }
    }

    private function nonNegativeInteger(string $name, mixed $value): void
    {
        if (! is_int($value) || $value < 0) {
            throw new InvalidQueueConfigurationException(
                "MySQLOptimizer queue {$name} must be a non-negative integer."
            );
        }
    }
}
