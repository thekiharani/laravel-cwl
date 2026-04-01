<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AWS Credentials
    |--------------------------------------------------------------------------
    |
    | Leave key and secret null to use the default credential provider chain
    | (IAM role, environment variables, ~/.aws/credentials, etc.).
    |
    */

    'credentials' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
    ],

    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),

    /*
    |--------------------------------------------------------------------------
    | Log Group & Stream
    |--------------------------------------------------------------------------
    |
    | The CloudWatch log group and stream name. The stream supports placeholders
    | that are resolved at runtime:
    |
    |   {app}        - APP_NAME
    |   {env}        - APP_ENV
    |   {date}       - Current date (Y-m-d)
    |   {hostname}   - Machine hostname
    |
    | Examples:
    |   "my-app-production"
    |   "{app}-{env}-{date}"
    |   "{app}-{hostname}"
    |
    */

    'log_group'  => env('CLOUDWATCH_LOG_GROUP', env('APP_NAME', 'laravel')),
    'log_stream' => env('CLOUDWATCH_LOG_STREAM', '{app}-{env}'),

    /*
    |--------------------------------------------------------------------------
    | Log Retention
    |--------------------------------------------------------------------------
    |
    | Retention in days for the log group. Set to null to keep logs forever.
    | Valid values: 1, 3, 5, 7, 14, 30, 60, 90, 120, 150, 180, 365, 400,
    | 545, 731, 1096, 1827, 2192, 2557, 2922, 3288, 3653.
    |
    */

    'retention' => env('CLOUDWATCH_LOG_RETENTION', 30),

    /*
    |--------------------------------------------------------------------------
    | Batch Size
    |--------------------------------------------------------------------------
    |
    | Number of log events to buffer before flushing to CloudWatch.
    | Set to 1 to send each log immediately (higher latency, lower throughput).
    |
    */

    'batch_size' => env('CLOUDWATCH_BATCH_SIZE', 25),

    /*
    |--------------------------------------------------------------------------
    | Log Level
    |--------------------------------------------------------------------------
    |
    | Minimum log level to send to CloudWatch.
    |
    */

    'level' => env('CLOUDWATCH_LOG_LEVEL', 'debug'),

    /*
    |--------------------------------------------------------------------------
    | Tags
    |--------------------------------------------------------------------------
    |
    | Key-value tags applied to the log group when it is created. Useful for
    | cost allocation, filtering, and organization in the AWS console.
    |
    | Example:
    |   'tags' => ['team' => 'backend', 'project' => 'noria'],
    |
    */

    'tags' => [],
];
