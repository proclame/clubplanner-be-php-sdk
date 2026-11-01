<?php

namespace Proclame\ClubPlanner\Tests;

use Orchestra\Testbench\TestCase as Testbench;
use Proclame\ClubPlanner\ClubPlanner;
use Proclame\ClubPlanner\Laravel\ClubPlannerServiceProvider;

class LaravelTest extends Testbench
{
    protected function getPackageProviders($app): array
    {
        return [ClubPlannerServiceProvider::class];
    }

    public function test_the_client_resolves_from_the_container_as_a_singleton(): void
    {
        $this->assertInstanceOf(ClubPlanner::class, $this->app->make(ClubPlanner::class));
        $this->assertSame($this->app->make(ClubPlanner::class), $this->app->make(ClubPlanner::class));
    }

    public function test_the_config_is_merged_with_its_defaults(): void
    {
        $this->assertSame('Europe/Brussels', config('clubplanner.timezone'));
        $this->assertSame([], config('clubplanner.redact'));
    }

    public function test_an_unconfigured_app_still_boots(): void
    {
        config(['clubplanner.base_url' => null, 'clubplanner.api_key' => null]);

        $this->assertInstanceOf(ClubPlanner::class, $this->app->make(ClubPlanner::class));
    }
}
