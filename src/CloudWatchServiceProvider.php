<?php

namespace NoriaLabs\CloudWatch;

use Illuminate\Support\ServiceProvider;

class CloudWatchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/cloudwatch.php', 'cloudwatch');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/cloudwatch.php' => config_path('cloudwatch.php'),
            ], 'cloudwatch-config');
        }

        $this->app->make('log')->extend('cloudwatch', function ($app, array $config) {
            return (new CloudWatchLoggerFactory())($config);
        });
    }
}
