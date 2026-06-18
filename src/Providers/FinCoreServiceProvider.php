<?php

namespace Nml\FinCore\Providers;

use Illuminate\Support\ServiceProvider;
use Nml\FinCore\Services\LedgerEngine;

class FinCoreServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Merge configuration
        $this->mergeConfigFrom(
            __DIR__ . '/../Config/accounting.php', 'accounting'
        );

        // Bind FacadeAccessor
        $this->app->singleton('fincore', function ($app) {
            return new LedgerEngine();
        });
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        // Load package migrations
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        // Publish config
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../Config/accounting.php' => config_path('accounting.php'),
            ], 'fincore-config');
        }
    }
}
