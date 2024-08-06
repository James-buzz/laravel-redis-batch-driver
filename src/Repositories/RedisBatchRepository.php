<?php

namespace JamesBuzz\RedisBatchDriver\Repositories;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\PrunableBatchRepository;
use Illuminate\Bus\UpdatedBatchJobCounts;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Str;
use RedisException;

class RedisBatchRepository implements PrunableBatchRepository
{
    /**
     * The batch factory instance.
     */
    protected BatchFactory $factory;

    /**
     * The database connection instance.
     */
    protected Connection $connection;

    /**
     * The redis key prefix for the batch repository.
     */
    protected string $redisKey;

    /**
     * Create a new batch repository instance.
     */
    public function __construct(
        BatchFactory $factory,
        Connection $connection,
        string $redisKey
    ) {
        $this->factory = $factory;
        $this->connection = $connection;
        $this->redisKey = $redisKey;
    }

    /**
     * Retrieve a list of batches.
     *
     * @param  int  $limit
     * @param  mixed  $before
     * @return \Illuminate\Bus\Batch[]
     *
     * @throws RedisException
     */
    public function get($limit, $before): array
    {
        $start = '+';
        if ($before) {
            $start = '('.$this->batchKey($before);
        }

        $batchIds = $this->connection->client()->zRangeByScore(
            $this->sortedIDsKey(), $start, '-', ['LIMIT' => [0, $limit]]
        );

        if (! $batchIds) {
            return [];
        }

        $batches = [];
        foreach ($batchIds as $batchId) {
            $batchData = $this->connection->client()->hGetAll($this->batchKey($batchId));
            $batches[] = $this->toBatch($batchData);
        }

        return $batches;
    }

    /**
     * Retrieve information about an existing batch.
     *
     * @throws RedisException
     */
    public function find(string $batchId): ?Batch
    {
        if ($batchId === '') {
            return null;
        }

        $batch = $this->connection->client()->hGetAll($this->batchKey($batchId));

        if (empty($batch)) {
            return null;
        }

        if (is_array($batch)) {
            $batch = (object) $batch;
        }

        return $this->toBatch($batch);
    }

    /**
     * Store a new pending batch.
     *
     * @throws RedisException
     */
    public function store(\Illuminate\Bus\PendingBatch $batch): ?Batch
    {
        $batchId = (string) Str::orderedUuid();
        $now = time();

        $batchData = [
            'id' => $batchId,
            'name' => $batch->name,
            'total_jobs' => 0,
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'options' => $this->serialize($batch->options),
            'created_at' => $now,
            'cancelled_at' => null,
            'finished_at' => null,
        ];

        $batchKey = $this->batchKey($batchId);

        $this->executeWithLock($batchId, function () use ($batchId, $batchKey, $now, $batchData) {
            $this->connection->client()->zAdd($this->sortedIDsKey(), 0, $batchKey);
            $this->connection->client()->zAdd($this->createdAtKey(), $now, $batchKey);
            $this->connection->client()->hMSet($batchKey, $batchData);

            $this->connection->client()->del($this->failedJobsKey($batchId));
        });

        return $this->find($batchId);
    }

    /**
     * Increment the total number of jobs within the batch.
     *
     * @throws RedisException
     */
    public function incrementTotalJobs(string $batchId, int $amount): void
    {
        $batchKey = $this->batchKey($batchId);

        $this->executeWithLock($batchId, function () use ($batchId, $batchKey, $amount) {
            $this->connection->client()->hIncrBy($batchKey, 'total_jobs', $amount);
            $this->connection->client()->hIncrBy($batchKey, 'pending_jobs', $amount);
            $this->connection->client()->hSet($batchKey, 'finished_at', null);
            $this->connection->client()->zRem($this->failedJobsKey($batchId), $batchKey);
        });
    }

    /**
     * Decrement the total number of pending jobs for the batch.
     *
     * @throws RedisException
     */
    public function decrementPendingJobs(string $batchId, string $jobId): UpdatedBatchJobCounts
    {
        $batchKey = $this->batchKey($batchId);

        $transaction = $this->executeWithLock($batchId, function () use ($batchId, $batchKey, $jobId) {
            $this->connection->client()->hIncrBy($batchKey, 'pending_jobs', -1);
            $this->connection->client()->hGet($batchKey, 'failed_jobs');
            $this->connection->client()->sRem($this->failedJobsKey($batchId), $jobId);
        });

        $pendingJobs = $transaction[0];
        $failedJobs = $transaction[1];

        return new UpdatedBatchJobCounts(
            $pendingJobs,
            $failedJobs
        );
    }

    /**
     * Increment the total number of failed jobs for the batch.
     *
     * @throws RedisException
     */
    public function incrementFailedJobs(string $batchId, string $jobId): UpdatedBatchJobCounts
    {
        $batchKey = $this->batchKey($batchId);

        $failedJobs = 0;
        $pendingJobs = 0;

        $this->executeWithLock($batchId, function () use ($batchId, $batchKey, $jobId, &$failedJobs, &$pendingJobs) {
            $failedJobs = $this->connection->client()->hIncrBy($batchKey, 'failed_jobs', 1);
            $pendingJobs = $this->connection->client()->hGet($batchKey, 'pending_jobs');
            $this->connection->client()->sAdd($this->failedJobsKey($batchId), $jobId);
        });

        return new UpdatedBatchJobCounts(
            $pendingJobs,
            $failedJobs
        );
    }

    /**
     * Mark the batch that has the given ID as finished.
     *
     * @throws RedisException
     */
    public function markAsFinished(string $batchId): void
    {
        $batchKey = $this->batchKey($batchId);

        $now = time();

        $this->executeWithLock($batchId, function () use ($batchKey, $now) {
            $this->connection->client()->hSet($batchKey, 'finished_at', $now);
            $this->connection->client()->zAdd($this->finishedAtKey(), $now, $batchKey);
        });
    }

    /**
     * Cancel the batch that has the given ID.
     *
     * @throws RedisException
     */
    public function cancel(string $batchId): void
    {
        $batchKey = $this->batchKey($batchId);

        $now = time();

        $this->executeWithLock($batchId, function () use ($batchKey, $now) {
            $this->connection->client()->hSet($batchKey, 'cancelled_at', $now);
            $this->connection->client()->hSet($batchKey, 'finished_at', $now);
            $this->connection->client()->zAdd($this->finishedAtKey(), $now, $batchKey);
        });
    }

    /**
     * Delete the batch that has the given ID.
     *
     * @throws RedisException
     */
    public function delete(string $batchId): void
    {
        $batchKey = $this->batchKey($batchId);

        $this->executeWithLock($batchId, function () use ($batchId, $batchKey) {
            $this->connection->client()->del([
                $batchKey,
                $this->failedJobsKey($batchId),
            ]);
            $this->connection->client()->zRem($this->sortedIDsKey(), $batchKey);
            $this->connection->client()->zRem($this->createdAtKey(), $batchKey);
            $this->connection->client()->zRem($this->finishedAtKey(), $batchKey);
        });
    }

    /**
     * Execute the given Closure within a storage specific transaction.
     */
    public function transaction(Closure $callback): mixed
    {
        return $callback();
    }

    /**
     * Rollback the last database transaction for the connection.
     *
     * @return void
     */
    public function rollBack() {}

    /**
     * Convert the given raw batch to a Batch object.
     *
     * @throws RedisException
     */
    protected function toBatch(object $batch): Batch
    {
        $failedJobIds = $this->connection->client()->sMembers($this->failedJobsKey($batch->id));

        return $this->factory->make(
            $this,
            $batch->id,
            $batch->name,
            (int) $batch->total_jobs,
            (int) $batch->pending_jobs,
            (int) $batch->failed_jobs,
            $failedJobIds,
            $this->unserialize($batch->options),
            CarbonImmutable::createFromTimestamp($batch->created_at, date_default_timezone_get()),
            $batch->cancelled_at ? CarbonImmutable::createFromTimestamp($batch->cancelled_at, date_default_timezone_get()) : null,
            $batch->finished_at ? CarbonImmutable::createFromTimestamp($batch->finished_at, date_default_timezone_get()) : null
        );
    }

    /**
     * Prune all the entries older than the given date.
     *
     * @throws RedisException
     */
    public function prune(DateTimeInterface $before): int
    {
        $totalDeleted = 0;

        $batchesKeys = $this->connection->client()->zRangeByScore('', '0', '('.$before->getTimestamp());

        foreach ($batchesKeys as $batchKey) {
            $batchId = $this->connection->client()->hGet($batchKey, 'id');
            $finishedAt = $this->connection->client()->hGet($batchKey, 'finished_at');
            if ($batchId && $finishedAt === null) {
                $this->delete($batchId);
                $totalDeleted++;
            }
        }

        return $totalDeleted;
    }

    /**
     * Performs an operation with a redis lock.
     *
     * @throws RedisException
     */
    protected function executeWithLock($batchKey, Closure $callback, $retryCount = 3, $sleepMs = 100)
    {
        $lockKey = "{$this->redisKey}:lock:{$batchKey}";

        $attempts = 0;
        while ($retryCount > 0) {
            if ($this->acquireLock($lockKey)) {
                try {
                    return $callback();
                } finally {
                    $this->releaseLock($lockKey);
                }
            } else {
                $attempts++;
                $retryCount--;

                if ($attempts < $retryCount) {
                    usleep($sleepMs * 1000);
                }
            }
        }

        throw new \RuntimeException("Failed to acquire lock for batch {$batchKey}");
    }

    /**
     * @throws RedisException
     */
    protected function acquireLock($lockKey): bool|\Redis
    {
        return $this->connection->client()->set($lockKey, 1, ['NX', 'EX' => 10]);
    }

    /**
     * @throws RedisException
     */
    protected function releaseLock($lockKey): void
    {
        $this->connection->client()->del([$lockKey]);
    }

    /**
     * Serialize the given value.
     */
    protected function serialize(mixed $value): string
    {
        return serialize($value);
    }

    /**
     * Unserialize the given value.
     */
    protected function unserialize(string $serialized): mixed
    {
        return unserialize($serialized);
    }

    /**
     * A key for storing the sorted IDs of the batches.
     */
    protected function sortedIDsKey(): string
    {
        return "{$this->redisKey}:ids";
    }

    /**
     * A key for storing the batch data.
     */
    protected function batchKey(string $batchId): string
    {
        return "{$this->redisKey}:{$batchId}";
    }

    /**
     * A key for storing the timestamps of the created batches.
     */
    protected function createdAtKey(): string
    {
        return "{$this->redisKey}:timestamps:created";
    }

    /**
     * A key for storing the timestamps of the finished batches.
     */
    protected function finishedAtKey(): string
    {
        return "{$this->redisKey}:timestamps:finished";
    }

    /**
     * A key for storing the failed jobs of the batch.
     */
    protected function failedJobsKey(string $batchId): string
    {
        return "{$this->redisKey}:{$batchId}:failed_jobs";
    }
}
