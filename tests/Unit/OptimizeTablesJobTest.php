<?php

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use MySQLOptimizer\Jobs\OptimizeTablesJob;

describe('OptimizeTablesJob', function () {
    it('implements ShouldQueue', function () {
        $job = new OptimizeTablesJob;

        expect($job)->toBeInstanceOf(ShouldQueue::class);
    });

    it('implements ShouldBeUnique', function () {
        $job = new OptimizeTablesJob;

        expect($job)->toBeInstanceOf(ShouldBeUnique::class);
    });

    it('has correct default property values', function () {
        $job = new OptimizeTablesJob;

        expect($job->tries)->toBe(3)
            ->and($job->timeout)->toBe(3600)
            ->and($job->uniqueFor)->toBe(3600)
            ->and($job->backoff)->toBe(3600);
    });

    it('accepts constructor parameters', function () {
        $job = new OptimizeTablesJob('my_database', ['users', 'posts'], false);

        expect($job->database)->toBe('my_database')
            ->and($job->tables)->toBe(['users', 'posts'])
            ->and($job->shouldLog)->toBeFalse();
    });

    it('has correct default constructor values', function () {
        $job = new OptimizeTablesJob;

        expect($job->database)->toBeNull()
            ->and($job->tables)->toBe([])
            ->and($job->shouldLog)->toBeTrue();
    });

    it('generates unique id based on database', function () {
        $job = new OptimizeTablesJob('my_database');

        expect($job->uniqueId())->toBe('optimize-tables:my_database');
    });

    it('generates unique id with default when no database specified', function () {
        $job = new OptimizeTablesJob;

        expect($job->uniqueId())->toBe('optimize-tables:default');
    });
});
