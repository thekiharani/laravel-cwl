<?php

use NoriaLabs\CloudWatch\CloudWatchServiceProvider;
use Orchestra\Testbench\TestCase;

uses(TestCase::class)
    ->beforeEach(function () {
        $this->app['config']->set('cloudwatch.credentials.key', 'test-key');
        $this->app['config']->set('cloudwatch.credentials.secret', 'test-secret');
        $this->app['config']->set('cloudwatch.region', 'us-east-1');
        $this->app['config']->set('cloudwatch.log_group', 'test-group');
        $this->app['config']->set('cloudwatch.log_stream', 'test-stream');
        $this->app['config']->set('cloudwatch.retention', 7);
        $this->app['config']->set('cloudwatch.batch_size', 10);
        $this->app['config']->set('cloudwatch.level', 'debug');
    })
    ->in('Feature');

uses()
    ->in('Unit');
