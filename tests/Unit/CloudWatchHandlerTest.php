<?php

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;
use Aws\Command;
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

function makeCloudWatchException(string $code): CloudWatchLogsException
{
    return new CloudWatchLogsException(
        $code,
        new Command('test'),
        ['code' => $code],
    );
}

it('buffers logs until batch size is reached', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

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

    $handler->handle(makeRecord('First'));
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
        batchSize: 100,
    );

    $handler->handle(makeRecord('Buffered'));
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

it('skips initialization when already initialized', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    // Only called once despite two flushes.
    $client->shouldReceive('createLogGroup')->once()->andReturn(new Result());
    $client->shouldReceive('createLogStream')->once()->andReturn(new Result());
    $client->shouldReceive('putLogEvents')->twice()->andReturn(new Result());

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 1,
    );

    $handler->handle(makeRecord('First'));
    $handler->handle(makeRecord('Second'));
});

it('retries after ResourceNotFoundException by reinitializing', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    // First init.
    $client->shouldReceive('createLogGroup')->twice()->andReturn(new Result());
    $client->shouldReceive('createLogStream')->twice()->andReturn(new Result());

    // First putLogEvents throws ResourceNotFoundException, second succeeds.
    $client->shouldReceive('putLogEvents')
        ->once()
        ->andThrow(makeCloudWatchException('ResourceNotFoundException'));
    $client->shouldReceive('putLogEvents')
        ->once()
        ->andReturn(new Result());

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 1,
    );

    $handler->handle(makeRecord());
});

it('rethrows non-ResourceNotFoundException from putLogEvents', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')->andReturn(new Result());
    $client->shouldReceive('createLogStream')->andReturn(new Result());
    $client->shouldReceive('putLogEvents')
        ->andThrow(makeCloudWatchException('AccessDeniedException'));

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 1,
    );

    expect(fn () => $handler->handle(makeRecord()))->toThrow(CloudWatchLogsException::class);

    // Clear the buffer so __destruct doesn't retry.
    $reflection = new ReflectionProperty($handler, 'buffer');
    $reflection->setValue($handler, []);
});

it('ignores ResourceAlreadyExistsException when creating log group', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')
        ->once()
        ->andThrow(makeCloudWatchException('ResourceAlreadyExistsException'));
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

it('rethrows non-ResourceAlreadyExistsException from createLogGroup', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')
        ->andThrow(makeCloudWatchException('AccessDeniedException'));

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 1,
    );

    expect(fn () => $handler->handle(makeRecord()))->toThrow(CloudWatchLogsException::class);

    $reflection = new ReflectionProperty($handler, 'buffer');
    $reflection->setValue($handler, []);
});

it('ignores ResourceAlreadyExistsException when creating log stream', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')->once()->andReturn(new Result());
    $client->shouldReceive('createLogStream')
        ->once()
        ->andThrow(makeCloudWatchException('ResourceAlreadyExistsException'));
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

it('rethrows non-ResourceAlreadyExistsException from createLogStream', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')->andReturn(new Result());
    $client->shouldReceive('createLogStream')
        ->andThrow(makeCloudWatchException('AccessDeniedException'));

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 1,
    );

    expect(fn () => $handler->handle(makeRecord()))->toThrow(CloudWatchLogsException::class);

    $reflection = new ReflectionProperty($handler, 'buffer');
    $reflection->setValue($handler, []);
});

it('passes tags to createLogGroup when provided', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')
        ->once()
        ->with(Mockery::on(fn ($args) => $args['tags'] === ['team' => 'backend', 'project' => 'noria']))
        ->andReturn(new Result());
    $client->shouldReceive('createLogStream')->once()->andReturn(new Result());
    $client->shouldReceive('putLogEvents')->once()->andReturn(new Result());

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 1,
        tags: ['team' => 'backend', 'project' => 'noria'],
    );

    $handler->handle(makeRecord());
});

it('omits tags from createLogGroup when empty', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $client->shouldReceive('createLogGroup')
        ->once()
        ->with(Mockery::on(fn ($args) => ! array_key_exists('tags', $args)))
        ->andReturn(new Result());
    $client->shouldReceive('createLogStream')->once()->andReturn(new Result());
    $client->shouldReceive('putLogEvents')->once()->andReturn(new Result());

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'test-stream',
        retention: null,
        batchSize: 1,
        tags: [],
    );

    $handler->handle(makeRecord());
});

it('resolves stream placeholders at flush time', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $expectedStream = 'myapp-staging-' . date('Y-m-d') . '-' . gethostname();

    $client->shouldReceive('createLogGroup')->once()->andReturn(new Result());
    $client->shouldReceive('createLogStream')
        ->once()
        ->with(Mockery::on(fn ($args) => $args['logStreamName'] === $expectedStream))
        ->andReturn(new Result());
    $client->shouldReceive('putLogEvents')
        ->once()
        ->with(Mockery::on(fn ($args) => $args['logStreamName'] === $expectedStream))
        ->andReturn(new Result());

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: '{app}-{env}-{date}-{hostname}',
        retention: null,
        batchSize: 1,
        streamContext: ['app' => 'myapp', 'env' => 'staging'],
    );

    $handler->handle(makeRecord());
});

it('reinitializes when resolved stream name changes', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    // First flush creates group + stream, second flush creates new stream.
    $client->shouldReceive('createLogGroup')->twice()->andReturn(new Result());
    $client->shouldReceive('createLogStream')->twice()->andReturn(new Result());
    $client->shouldReceive('putLogEvents')->twice()->andReturn(new Result());

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: 'static-stream',
        retention: null,
        batchSize: 1,
    );

    // First log flushes with 'static-stream'.
    $handler->handle(makeRecord('First'));

    // Simulate stream name change (e.g. date rollover) by updating the template.
    $templateRef = new ReflectionProperty($handler, 'logStreamTemplate');
    $templateRef->setValue($handler, 'new-stream');

    // Second log flushes — detects stream change and reinitializes.
    $handler->handle(makeRecord('Second'));
});

it('uses default values for missing stream context', function () {
    $client = Mockery::mock(CloudWatchLogsClient::class);

    $expectedStream = 'laravel-production';

    $client->shouldReceive('createLogGroup')->once()->andReturn(new Result());
    $client->shouldReceive('createLogStream')
        ->once()
        ->with(Mockery::on(fn ($args) => $args['logStreamName'] === $expectedStream))
        ->andReturn(new Result());
    $client->shouldReceive('putLogEvents')->once()->andReturn(new Result());

    $handler = new CloudWatchHandler(
        client: $client,
        logGroup: 'test-group',
        logStream: '{app}-{env}',
        retention: null,
        batchSize: 1,
        streamContext: [], // No context — should use defaults.
    );

    $handler->handle(makeRecord());
});
