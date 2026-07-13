<?php

namespace MySQLOptimizer\Jobs\Concerns;

use Illuminate\Container\Container;
use MySQLOptimizer\Support\QueueConfigurationValidator;

trait ConfiguresOptimizerQueue
{
    protected function configureOptimizerQueue(): void
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return;
        }

        $config = $container->make('config');
        $validator = $container->make(QueueConfigurationValidator::class);

        $this->timeout = $config->get('mysql-optimizer.queue.timeout', 3600);
        $this->tries = $config->get('mysql-optimizer.queue.tries', 1);
        $this->backoff = $config->get('mysql-optimizer.queue.backoff', 3600);
        $this->uniqueFor = $config->get('mysql-optimizer.queue.unique_for', 0);

        $validator->validateRuntime($this->timeout, $this->tries, $this->backoff, $this->uniqueFor);

        $connection = $validator->validateConnectionName(
            $config->get('mysql-optimizer.queue.connection')
        );
        if ($connection !== null) {
            $this->onConnection($connection);
        }

        $queue = $validator->validateQueueName($config->get('mysql-optimizer.queue.name'));
        if ($queue !== null) {
            $this->onQueue($queue);
        }
    }

    protected function validateActualQueueConfiguration(): string
    {
        return app(QueueConfigurationValidator::class)->validate(
            $this->actualConnectionName(),
            $this->timeout,
            $this->tries,
            $this->backoff,
            $this->uniqueFor,
        );
    }

    protected function actualConnectionName(): ?string
    {
        return $this->job?->getConnectionName() ?? $this->connection;
    }

    protected function actualQueueName(): ?string
    {
        if ($this->job !== null && is_callable([$this->job, 'getQueue'])) {
            $queue = $this->job->getQueue();

            if (is_string($queue) && $queue !== '') {
                return $queue;
            }
        }

        return $this->queue;
    }

    public function routeTo(string $connection, ?string $queue): void
    {
        $this->onConnection($connection);

        if ($queue !== null) {
            $this->onQueue($queue);
        }
    }

    protected function queueJobContext(): array
    {
        return [
            'id' => $this->job?->getJobId(),
            'attempts' => $this->job?->attempts(),
            'timeout' => $this->job?->timeout() ?? $this->timeout,
        ];
    }
}
