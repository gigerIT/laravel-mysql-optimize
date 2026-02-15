<?php

namespace Tests\Integration;

use Tests\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => env('MYSQL_HOST', '127.0.0.1'),
            'port' => env('MYSQL_PORT', '3306'),
            'database' => env('MYSQL_DATABASE', 'testing'),
            'username' => env('MYSQL_USERNAME', 'root'),
            'password' => env('MYSQL_PASSWORD', ''),
        ]);
        $app['config']->set('mysql-optimizer.database', env('MYSQL_DATABASE', 'testing'));
    }
}
