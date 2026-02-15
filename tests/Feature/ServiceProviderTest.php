<?php

describe('ServiceProvider', function () {
    it('registers the db:optimize command', function () {
        $this->artisan('list')
            ->assertSuccessful();

        // Verify the command exists by checking if artisan can find it
        $commands = \Illuminate\Support\Facades\Artisan::all();

        expect($commands)->toHaveKey('db:optimize');
    });

    it('merges package configuration', function () {
        expect(config('mysql-optimizer'))->toBeArray()
            ->and(config('mysql-optimizer'))->toHaveKey('database');
    });
});
