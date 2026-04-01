<?php

namespace NoriaLabs\CloudWatch;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

class CloudWatchLoggerFactory
{
    public function __invoke(array $config): LoggerInterface
    {
        $cwConfig = app('config')->get('cloudwatch');

        $credentials = array_filter([
            'key'    => $cwConfig['credentials']['key'] ?? null,
            'secret' => $cwConfig['credentials']['secret'] ?? null,
        ]);

        $clientConfig = [
            'region'  => $cwConfig['region'],
            'version' => 'latest',
        ];

        if (count($credentials) === 2) {
            $clientConfig['credentials'] = $credentials;
        }

        $client = new CloudWatchLogsClient($clientConfig);

        $handler = new CloudWatchHandler(
            client:        $client,
            logGroup:      $cwConfig['log_group'],
            logStream:     $cwConfig['log_stream'],
            retention:     $cwConfig['retention'],
            batchSize:     $cwConfig['batch_size'],
            level:         Level::fromName($config['level'] ?? $cwConfig['level']),
            tags:          $cwConfig['tags'] ?? [],
            streamContext: [
                'app' => (string) app('config')->get('app.name', 'laravel'),
                'env' => (string) app('config')->get('app.env', 'production'),
            ],
        );

        $handler->setFormatter(new JsonFormatter());

        return new Logger('cloudwatch', [$handler]);
    }
}
