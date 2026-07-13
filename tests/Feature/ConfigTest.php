<?php

describe('mysql-optimizer config', function () {
    it('has database key', function () {
        expect(config('mysql-optimizer'))->toHaveKey('database');
    });

    it('allows overriding database value', function () {
        config(['mysql-optimizer.database' => 'custom_database']);

        expect(config('mysql-optimizer.database'))->toBe('custom_database');
    });

    it('has safe queue defaults', function () {
        expect(config('mysql-optimizer.queue'))->toBe([
            'per_table' => false,
            'connection' => null,
            'name' => null,
            'timeout' => 3600,
            'tries' => 1,
            'backoff' => 3600,
            'unique_for' => 0,
        ]);
    });
});
