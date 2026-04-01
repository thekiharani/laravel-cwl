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
    private string $logStream;
    private ?int $retention;
    private int $batchSize;
    private bool $initialized = false;

    /** @var array<int, array{timestamp: int, message: string}> */
    private array $buffer = [];

    public function __construct(
        CloudWatchLogsClient $client,
        string $logGroup,
        string $logStream,
        ?int $retention = 30,
        int $batchSize = 25,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);

        $this->client = $client;
        $this->logGroup = $logGroup;
        $this->logStream = $logStream;
        $this->retention = $retention;
        $this->batchSize = $batchSize;
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

        $this->ensureInitialized();

        // CloudWatch requires events sorted by timestamp.
        usort($this->buffer, fn (array $a, array $b) => $a['timestamp'] <=> $b['timestamp']);

        try {
            $this->client->putLogEvents([
                'logGroupName'  => $this->logGroup,
                'logStreamName' => $this->logStream,
                'logEvents'     => $this->buffer,
            ]);
        } catch (CloudWatchLogsException $e) {
            // If the stream was deleted externally, recreate and retry once.
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                $this->initialized = false;
                $this->ensureInitialized();

                $this->client->putLogEvents([
                    'logGroupName'  => $this->logGroup,
                    'logStreamName' => $this->logStream,
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
            $this->client->createLogGroup([
                'logGroupName' => $this->logGroup,
            ]);

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
                'logStreamName' => $this->logStream,
            ]);
        } catch (CloudWatchLogsException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceAlreadyExistsException') {
                throw $e;
            }
        }
    }
}
