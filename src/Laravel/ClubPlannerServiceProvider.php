<?php

namespace Proclame\ClubPlanner\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Proclame\ClubPlanner\ClubPlanner;

class ClubPlannerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/clubplanner.php', 'clubplanner');

        $this->app->singleton(ClubPlanner::class, function (Application $app): ClubPlanner {
            $config = $app->make('config')->get('clubplanner');

            return new ClubPlanner(
                baseUrl: (string) $config['base_url'],
                apiKey: $config['api_key'],
                version: (string) $config['version'],
                timeout: $config['timeout'],
                timezone: (string) $config['timezone'],
                redact: (array) $config['redact'],
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/clubplanner.php' => $this->app->configPath('clubplanner.php'),
        ], 'clubplanner-config');
    }
}
