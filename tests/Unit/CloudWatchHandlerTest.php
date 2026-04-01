<?php

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\Result;
use Monolog\Level;
use Monolog\LogRecord;
use NoriaLabs\CloudWatch\CloudWatchHandler;

function makeRecord(string $message = 'Test message', Level $level = Level::Info): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable(),
        channel: 'test',
        level: $level,
        message: $message,
    );
}

it('buffers logs until batch size is reached', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    // Initialization: createLogGroup, createLogStream
    // Then putLogEvents on flush
    $client->shouldReceive('createLogGroup')->once()->andReturn(new Result());
    $client->shouldReceive('createLogStream')->once()->andReturn(new Result());
    $client->shouldReceive('putLogEvents')->once()->andReturn(new Result());

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 2,
    );

    // First log — should buffer, not flush.
    $handler->handle(makeRecord('First'));

    // Second log — hits batch size, triggers flush.
    $handler->handle(makeRecord('Second'));
});

it('flushes remaining logs on close', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')->once()->andReturn(new Result());
    $client->shouldReceive('createLogStream')->once()->andReturn(new Result());
    $client->shouldReceive('putLogEvents')->once()->andReturn(new Result());

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 100, // Large batch so it won't auto-flush.
    );

    $handler->handle(makeRecord('Buffered'));

    // Nothing flushed yet — close should trigger it.
    $handler->close();
});

it('does nothing when flushing an empty buffer', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldNotReceive('putLogEvents');
    $client->shouldNotReceive('createLogGroup');
    $client->shouldNotReceive('createLogStream');

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 10,
    );

    $handler->flush();
});

it('sets retention policy when configured', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')->once()->andReturn(new Result());
    $client->shouldReceive('putRetentionPolicy')
        ->once()
        ->with(Mockery::on(fn ($args) => $args['retentionInDays'] === 14))
        ->andReturn(new Result());
    $client->shouldReceive('createLogStream')->once()->andReturn(new Result());
    $client->shouldReceive('putLogEvents')->once()->andReturn(new Result());

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: 14,
        batchSize: 1,
    );

    $handler->handle(makeRecord());
});

it('skips retention policy when set to null', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')->once()->andReturn(new Result());
    $client->shouldNotReceive('putRetentionPolicy');
    $client->shouldReceive('createLogStream')->once()->andReturn(new Result());
    $client->shouldReceive('putLogEvents')->once()->andReturn(new Result());

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 1,
    );

    $handler->handle(makeRecord());
});
