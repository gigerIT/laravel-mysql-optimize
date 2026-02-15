<?php

describe('mysql-optimizer config', function () {
    it('has database key', function () {
        expect(config('mysql-optimizer'))->toHaveKey('database');
    });

    it('allows overriding database value', function () {
        config(['mysql-optimizer.database' => 'custom_database']);

        expect(config('mysql-optimizer.database'))->toBe('custom_database');
    });
});
