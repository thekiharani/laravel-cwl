# Laravel CloudWatch Logger

AWS CloudWatch log driver for Laravel. Drop-in replacement — just switch your log channel.

## Installation

```bash
composer require norialabs/laravel-cloudwatch-logger
```

The service provider is auto-discovered. To publish the config:

```bash
php artisan vendor:publish --tag=cloudwatch-config
```

## Configuration

Add a `cloudwatch` channel to `config/logging.php`:

```php
'channels' => [
    // ...

    'cloudwatch' => [
        'driver' => 'cloudwatch',
        'level'  => env('LOG_LEVEL', 'debug'),
    ],
],
```

Set your environment variables:

```env
LOG_CHANNEL=cloudwatch

AWS_ACCESS_KEY_ID=your-key
AWS_SECRET_ACCESS_KEY=your-secret
AWS_DEFAULT_REGION=us-east-1

CLOUDWATCH_LOG_GROUP=my-app
CLOUDWATCH_LOG_STREAM=my-app-production
CLOUDWATCH_LOG_RETENTION=30
CLOUDWATCH_BATCH_SIZE=25
```

If running on EC2/ECS/Lambda with an IAM role, omit the AWS credentials — the SDK picks them up automatically.

## Usage

Nothing changes. Use Laravel's logger as normal:

```php
Log::info('Payment processed', ['amount' => 1000, 'currency' => 'KES']);
Log::error('M-Pesa callback failed', ['error' => $exception->getMessage()]);
```

### Stack driver

Combine with other channels:

```php
'channels' => [
    'stack' => [
        'driver'   => 'stack',
        'channels' => ['single', 'cloudwatch'],
    ],
],
```

## How it works

- Registers a custom Monolog driver via Laravel's log manager
- Buffers log events and flushes in batches to CloudWatch (configurable batch size)
- Auto-creates the log group and stream if they don't exist
- Sets retention policy on the log group
- Flushes remaining buffered logs on shutdown
- Outputs JSON-formatted log entries

## License

MIT
