<?php

use Monolog\Logger;
use NoriaLabs\CloudWatch\CloudWatchHandler;
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

it('stores the stream template for runtime resolution', function () {
    $this->app['config']->set('app.name', 'myapp');
    $this->app['config']->set('app.env', 'staging');
    $this->app['config']->set('cloudwatch.log_stream', '{app}-{env}-{date}-{hostname}');

    $factory = new CloudWatchLoggerFactory();
    /** @var Logger $logger */
    $logger = $factory(['level' => 'debug']);
    $handler = $logger->getHandlers()[0];

    // Template is stored raw, not pre-resolved.
    $templateRef = new ReflectionProperty($handler, 'logStreamTemplate');
    expect($templateRef->getValue($handler))->toBe('{app}-{env}-{date}-{hostname}');

    // Context carries app and env from config.
    $contextRef = new ReflectionProperty($handler, 'streamContext');
    expect($contextRef->getValue($handler))->toBe([
        'app' => 'myapp',
        'env' => 'staging',
    ]);
});

it('passes tags from config to the handler', function () {
    $this->app['config']->set('cloudwatch.tags', ['team' => 'backend']);

    $factory = new CloudWatchLoggerFactory();
    /** @var Logger $logger */
    $logger = $factory(['level' => 'debug']);
    $handler = $logger->getHandlers()[0];

    $reflection = new ReflectionProperty($handler, 'tags');
    expect($reflection->getValue($handler))->toBe(['team' => 'backend']);
});

it('uses IAM credentials when key and secret are not set', function () {
    $this->app['config']->set('cloudwatch.credentials.key', null);
    $this->app['config']->set('cloudwatch.credentials.secret', null);

    $factory = new CloudWatchLoggerFactory();
    $logger = $factory(['level' => 'debug']);

    expect($logger)->toBeInstanceOf(LoggerInterface::class);
});
