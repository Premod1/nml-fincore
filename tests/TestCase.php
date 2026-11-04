<?php

namespace Nml\FinCore\Tests;

use Nml\FinCore\Providers\FinCoreServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            FinCoreServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('accounting.table_prefix', 'fincore_');
        $app['config']->set('accounting.currency', 'LKR');
        $app['config']->set('accounting.rounding_tolerance', 0.005);
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../src/Database/Migrations');
    }
}
