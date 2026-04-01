<?php

namespace NoriaLabs\CloudWatch;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class CloudWatchHandler extends AbstractProcessingHandler
{
    private CloudWatchLogsClient $client;
    private string $logGroup;
    private string $logStreamTemplate;
    private ?int $retention;
    private int $batchSize;
    /** @var array<string, string> */
    private array $tags;
    /** @var array<string, string> */
    private array $streamContext;

    private ?string $resolvedStream = null;
    private bool $initialized = false;

    /** @var array<int, array{timestamp: int, message: string}> */
    private array $buffer = [];

    /**
     * @param  string  $logStream  Stream name or template with placeholders: {app}, {env}, {date}, {hostname}
     * @param  array<string, string>  $tags  Key-value tags applied to the log group on creation.
     * @param  array<string, string>  $streamContext  Values for {app} and {env} placeholders. {date} and {hostname} are always resolved at flush time.
     */
    public function __construct(
        CloudWatchLogsClient $client,
        string $logGroup,
        string $logStream,
        ?int $retention = 30,
        int $batchSize = 25,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        array $tags = [],
        array $streamContext = [],
    ) {
        parent::__construct($level, $bubble);

        $this->client = $client;
        $this->logGroup = $logGroup;
        $this->logStreamTemplate = $logStream;
        $this->retention = $retention;
        $this->batchSize = $batchSize;
        $this->tags = $tags;
        $this->streamContext = $streamContext;
    }

    protected function write(LogRecord $record): void
    {
        $this->buffer[] = [
            'timestamp' => $record->datetime->getTimestamp() * 1000,
            'message'   => $record->formatted ?? $record->message,
        ];

        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $stream = $this->resolveStream();

        // If the stream changed (e.g. date rolled over), reinitialize.
        if ($stream !== $this->resolvedStream) {
            $this->resolvedStream = $stream;
            $this->initialized = false;
        }

        $this->ensureInitialized();

        // CloudWatch requires events sorted by timestamp.
        usort($this->buffer, fn (array $a, array $b) => $a['timestamp'] <=> $b['timestamp']);

        try {
            $this->client->putLogEvents([
                'logGroupName'  => $this->logGroup,
                'logStreamName' => $this->resolvedStream,
                'logEvents'     => $this->buffer,
            ]);
        } catch (CloudWatchLogsException $e) {
            // If the stream was deleted externally, recreate and retry once.
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                $this->initialized = false;
                $this->ensureInitialized();

                $this->client->putLogEvents([
                    'logGroupName'  => $this->logGroup,
                    'logStreamName' => $this->resolvedStream,
                    'logEvents'     => $this->buffer,
                ]);
            } else {
                throw $e;
            }
        }

        $this->buffer = [];
    }

    public function close(): void
    {
        $this->flush();
        parent::close();
    }

    public function __destruct()
    {
        $this->flush();
    }

    protected function getDefaultFormatter(): JsonFormatter
    {
        return new JsonFormatter();
    }

    private function resolveStream(): string
    {
        return str_replace(
            ['{app}', '{env}', '{date}', '{hostname}'],
            [
                $this->streamContext['app'] ?? 'laravel',
                $this->streamContext['env'] ?? 'production',
                date('Y-m-d'),
                gethostname() ?: 'unknown',
            ],
            $this->logStreamTemplate,
        );
    }

    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->ensureLogGroupExists();
        $this->ensureLogStreamExists();

        $this->initialized = true;
    }

    private function ensureLogGroupExists(): void
    {
        try {
            $params = ['logGroupName' => $this->logGroup];

            if (! empty($this->tags)) {
                $params['tags'] = $this->tags;
            }

            $this->client->createLogGroup($params);

            if ($this->retention !== null) {
                $this->client->putRetentionPolicy([
                    'logGroupName'    => $this->logGroup,
                    'retentionInDays' => $this->retention,
                ]);
            }
        } catch (CloudWatchLogsException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceAlreadyExistsException') {
                throw $e;
            }
        }
    }

    private function ensureLogStreamExists(): void
    {
        try {
            $this->client->createLogStream([
                'logGroupName'  => $this->logGroup,
                'logStreamName' => $this->resolvedStream,
            ]);
        } catch (CloudWatchLogsException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceAlreadyExistsException') {
                throw $e;
            }
        }
    }
}
