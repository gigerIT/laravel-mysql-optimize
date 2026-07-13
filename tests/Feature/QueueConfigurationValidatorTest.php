<?php

use MySQLOptimizer\Exceptions\InvalidQueueConfigurationException;
use MySQLOptimizer\Support\QueueConfigurationValidator;

describe('QueueConfigurationValidator', function () {
    it('uses runtime defaults with partial package queue configuration', function () {
        config([
            'mysql-optimizer.queue' => ['name' => 'mysql-optimizer'],
            'queue.default' => 'optimizer',
            'queue.connections.optimizer' => ['driver' => 'redis', 'retry_after' => 3610],
        ]);

        expect(app(QueueConfigurationValidator::class)->validateConfigured())->toBe('optimizer');
    });

    it('resolves the inherited default connection', function () {
        config([
            'queue.default' => 'optimizer',
            'queue.connections.optimizer' => ['driver' => 'redis', 'retry_after' => 110],
        ]);

        expect(app(QueueConfigurationValidator::class)->validate(null, 100))->toBe('optimizer');
    });

    it('accepts the minimum retry_after safety margin for inspectable drivers', function (string $driver) {
        config(['queue.connections.optimizer' => ['driver' => $driver, 'retry_after' => 110]]);

        expect(app(QueueConfigurationValidator::class)->validate('optimizer', 100))->toBe('optimizer');
    })->with(['redis', 'database', 'beanstalkd']);

    it('rejects retry_after below the minimum safety margin', function () {
        config(['queue.connections.optimizer' => ['driver' => 'redis', 'retry_after' => 109]]);

        expect(fn () => app(QueueConfigurationValidator::class)->validate('optimizer', 100))
            ->toThrow(InvalidQueueConfigurationException::class, 'minimum safety margin [10 seconds]');
    });

    it('rejects retry_after less than or equal to job timeout regardless of tries or backoff', function (int $retryAfter, int $tries, int $backoff) {
        config(['queue.connections.optimizer' => [
            'driver' => 'redis',
            'retry_after' => $retryAfter,
        ]]);

        expect(fn () => app(QueueConfigurationValidator::class)->validate('optimizer', 180, $tries, $backoff, 0))
            ->toThrow(InvalidQueueConfigurationException::class, 'optimizer');
    })->with([
        'issue #4 configuration' => [180, 3, 3600],
        'below timeout' => [179, 1, 0],
    ]);

    it('uses the Laravel 60 second retry_after fallback when omitted', function () {
        config(['queue.connections.optimizer' => ['driver' => 'redis']]);

        expect(fn () => app(QueueConfigurationValidator::class)->validate('optimizer', 60))
            ->toThrow(InvalidQueueConfigurationException::class, 'retry_after [60]');
    });

    it('reports actionable unsafe configuration details', function () {
        config(['queue.connections.optimizer' => ['driver' => 'redis', 'retry_after' => 180]]);

        expect(fn () => app(QueueConfigurationValidator::class)->validate('optimizer', 3600))
            ->toThrow(InvalidQueueConfigurationException::class, 'connection [optimizer] using driver [redis]')
            ->toThrow(InvalidQueueConfigurationException::class, 'job timeout [3600]')
            ->toThrow(InvalidQueueConfigurationException::class, 'retry_after [180]')
            ->toThrow(InvalidQueueConfigurationException::class, 'job timeout + safety margin <= queue retry_after')
            ->toThrow(InvalidQueueConfigurationException::class, 'When Horizon processes this queue')
            ->toThrow(InvalidQueueConfigurationException::class, 'dedicated optimizer queue');
    });

    it('bypasses sync and uninspectable drivers', function (string $driver) {
        config(['queue.connections.optimizer' => ['driver' => $driver]]);

        expect(app(QueueConfigurationValidator::class)->validate('optimizer', 3600))->toBe('optimizer');
    })->with(['sync', 'sqs', 'custom']);

    it('rejects missing connections', function () {
        expect(fn () => app(QueueConfigurationValidator::class)->validate('missing', 3600))
            ->toThrow(InvalidQueueConfigurationException::class, 'missing');
    });
});
