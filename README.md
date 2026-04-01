# Laravel CWL

AWS CloudWatch Logs driver for Laravel. Drop-in replacement — just switch your log channel.

## Requirements

- PHP 8.2+
- Laravel 11, 12, or 13
- An AWS account with CloudWatch Logs access

## Installation

```bash
composer require thekiharani/laravel-cwl
```

The service provider is auto-discovered. Publish the config file:

```bash
php artisan vendor:publish --provider="NoriaLabs\CloudWatch\CloudWatchServiceProvider"
```

This creates `config/cloudwatch.php`.

## Quick Start

1. Add a `cloudwatch` channel to `config/logging.php`:

```php
'channels' => [
    // ...

    'cloudwatch' => [
        'driver' => 'cloudwatch',
        'level'  => env('LOG_LEVEL', 'debug'),
    ],
],
```

2. Set your environment:

```env
LOG_CHANNEL=cloudwatch
```

3. Use Laravel's logger as normal:

```php
Log::info('Payment processed', ['amount' => 1000, 'currency' => 'KES']);
```

That's it. Logs go to CloudWatch.

## Configuration

All options are configured via environment variables with sensible defaults.

### AWS Credentials

| Variable | Default | Description |
|----------|---------|-------------|
| `AWS_ACCESS_KEY_ID` | `null` | AWS access key |
| `AWS_SECRET_ACCESS_KEY` | `null` | AWS secret key |
| `AWS_DEFAULT_REGION` | `us-east-1` | AWS region for CloudWatch |

If both key and secret are `null`, the SDK uses the [default credential provider chain](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/guide_credentials.html) — IAM roles (EC2/ECS/Lambda), environment variables, `~/.aws/credentials`, etc. This is the recommended approach for production.

### Log Group

| Variable | Default | Description |
|----------|---------|-------------|
| `CLOUDWATCH_LOG_GROUP` | `APP_NAME` (or `laravel`) | CloudWatch log group name |

The log group is auto-created on the first log event if it doesn't exist.

### Log Stream

| Variable | Default | Description |
|----------|---------|-------------|
| `CLOUDWATCH_LOG_STREAM` | `{app}-{env}` | Stream name or template |

The stream name supports **dynamic placeholders** that are resolved at flush time (not at boot time):

| Placeholder | Resolves to | Example |
|-------------|-------------|---------|
| `{app}` | `APP_NAME` config value | `noria` |
| `{env}` | `APP_ENV` config value | `production` |
| `{date}` | Current date (`Y-m-d`) | `2026-04-01` |
| `{hostname}` | Machine hostname | `web-01` |

**Examples:**

```env
# Static stream (default)
CLOUDWATCH_LOG_STREAM="{app}-{env}"
# Result: noria-production

# Daily streams — each day gets its own stream automatically
CLOUDWATCH_LOG_STREAM="{app}-{env}-{date}"
# Result: noria-production-2026-04-01

# Per-host streams — useful for multi-server deploys
CLOUDWATCH_LOG_STREAM="{app}-{hostname}"
# Result: noria-web-01

# Combine all
CLOUDWATCH_LOG_STREAM="{app}-{env}-{hostname}-{date}"
# Result: noria-production-web-01-2026-04-01
```

**Long-running processes:** Placeholders are resolved every time the buffer is flushed, not once at startup. This means queue workers and schedulers will automatically create a new stream at midnight when using `{date}` — no restart required.

Log streams are auto-created on first use.

### Log Retention

| Variable | Default | Description |
|----------|---------|-------------|
| `CLOUDWATCH_LOG_RETENTION` | `30` | Retention in days, or `null` for forever |

The retention policy is applied when the log group is created. Valid values: `1`, `3`, `5`, `7`, `14`, `30`, `60`, `90`, `120`, `150`, `180`, `365`, `400`, `545`, `731`, `1096`, `1827`, `2192`, `2557`, `2922`, `3288`, `3653`.

Set to `null` to retain logs indefinitely:

```env
CLOUDWATCH_LOG_RETENTION=
```

### Batch Size

| Variable | Default | Description |
|----------|---------|-------------|
| `CLOUDWATCH_BATCH_SIZE` | `25` | Events buffered before flushing to CloudWatch |

Logs are buffered in memory and sent in batches to reduce API calls. The buffer is always flushed on application shutdown, so no logs are lost.

- **Higher values** (50-100): fewer API calls, better throughput, slightly delayed delivery
- **Lower values** (1-5): near-real-time delivery, more API calls
- **Set to `1`**: every log is sent immediately (useful for debugging)

### Log Level

| Variable | Default | Description |
|----------|---------|-------------|
| `CLOUDWATCH_LOG_LEVEL` | `debug` | Minimum level to send to CloudWatch |

Standard Monolog/PSR-3 levels: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`.

You can also override the level per-channel in `config/logging.php`:

```php
'cloudwatch' => [
    'driver' => 'cloudwatch',
    'level'  => 'warning', // Only warnings and above
],
```

### Tags

Tags are key-value pairs applied to the log group when it is created. Useful for cost allocation, filtering, and organization in the AWS console.

Configure in `config/cloudwatch.php`:

```php
'tags' => [
    'team'    => 'backend',
    'project' => 'noria',
    'env'     => env('APP_ENV', 'production'),
],
```

Tags are only applied when the log group is first created. To update tags on an existing group, use the AWS console or CLI.

## Usage Examples

### Basic logging

```php
use Illuminate\Support\Facades\Log;

Log::info('User registered', ['user_id' => 42]);
Log::error('Payment failed', ['order_id' => 'ORD-123', 'reason' => 'insufficient_funds']);
Log::debug('Cache miss', ['key' => 'user:42:profile']);
```

### Stack driver

Send logs to multiple channels simultaneously:

```php
// config/logging.php
'channels' => [
    'stack' => [
        'driver'   => 'stack',
        'channels' => ['single', 'cloudwatch'],
    ],
],
```

### Local development with fallback

Use CloudWatch in production, local file in development:

```env
# .env (local)
LOG_CHANNEL=single

# .env (production)
LOG_CHANNEL=cloudwatch
```

### Multiple CloudWatch channels

Send different log types to different groups:

```php
// config/logging.php
'channels' => [
    'cloudwatch' => [
        'driver' => 'cloudwatch',
        'level'  => 'info',
    ],

    'cloudwatch-errors' => [
        'driver' => 'cloudwatch',
        'level'  => 'error',
    ],
],
```

Override the group/stream per channel by setting different env vars or adjusting `config/cloudwatch.php`.

## How It Works

1. **Driver registration** — The service provider registers a `cloudwatch` driver with Laravel's log manager via Monolog.
2. **Buffering** — Log events are buffered in memory until the batch size is reached or the application shuts down.
3. **Stream resolution** — On each flush, the stream name template is resolved with current values (`{date}` = today). If the resolved name changed since the last flush, a new stream is created automatically.
4. **Auto-creation** — The log group and stream are created on first use. If they already exist, the `ResourceAlreadyExistsException` is silently ignored.
5. **Flushing** — Buffered events are sorted by timestamp and sent to CloudWatch via `putLogEvents`.
6. **Self-healing** — If the stream is deleted externally mid-run, the handler catches the `ResourceNotFoundException`, recreates the group/stream, and retries the flush.
7. **Shutdown** — Any remaining buffered logs are flushed on `close()` and `__destruct()`, so logs are never lost.

All log entries are JSON-formatted via Monolog's `JsonFormatter`.

## IAM Permissions

The AWS credentials you provide need the following permissions:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "logs:CreateLogGroup",
                "logs:CreateLogStream",
                "logs:PutLogEvents",
                "logs:PutRetentionPolicy"
            ],
            "Resource": "arn:aws:logs:*:*:log-group:YOUR_LOG_GROUP:*"
        }
    ]
}
```

Replace `YOUR_LOG_GROUP` with your actual log group name, or use `*` for all groups.

If you set `retention` to `null`, you can omit `logs:PutRetentionPolicy`.

## Full Configuration Reference

```env
# AWS (omit for IAM role)
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1

# CloudWatch
CLOUDWATCH_LOG_GROUP=my-app                    # Default: APP_NAME
CLOUDWATCH_LOG_STREAM={app}-{env}              # Default: {app}-{env}
CLOUDWATCH_LOG_RETENTION=30                    # Default: 30 (days, null = forever)
CLOUDWATCH_BATCH_SIZE=25                       # Default: 25
CLOUDWATCH_LOG_LEVEL=debug                     # Default: debug
```

```php
// config/cloudwatch.php — tags (not env-configurable)
'tags' => [
    'team'    => 'backend',
    'project' => 'noria',
],
```

## Testing

```bash
composer test
# or
./vendor/bin/pest
./vendor/bin/pest --coverage
```


## License

MIT
