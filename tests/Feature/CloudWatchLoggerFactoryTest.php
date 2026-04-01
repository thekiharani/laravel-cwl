<?php

use NoriaLabs\CloudWatch\CloudWatchLoggerFactory;
use NoriaLabs\CloudWatch\CloudWatchServiceProvider;
use Psr\Log\LoggerInterface;

beforeEach(function () {
    $this->app->register(CloudWatchServiceProvider::class);
});

it('creates a logger instance from the factory', function () {
    $factory = new CloudWatchLoggerFactory();
    $logger = $factory(['level' => 'info']);

    expect($logger)->toBeInstanceOf(LoggerInterface::class);
});

it('registers the cloudwatch driver with the log manager', function () {
    $this->app['config']->set('logging.channels.cloudwatch', [
        'driver' => 'cloudwatch',
        'level'  => 'debug',
    ]);

    $logger = $this->app->make('log')->channel('cloudwatch');

    expect($logger)->not->toBeNull();
});
