<?php

namespace JamesBuzz\RedisBatchDriver\Tests\Unit;

use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\PendingBatch;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Collection;
use JamesBuzz\RedisBatchDriver\Repositories\RedisBatchRepository;
use JamesBuzz\RedisBatchDriver\Tests\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class RedisBatchRepositoryTest extends TestCase
{
    public PhpRedisConnection|PredisConnection $redis;

    public function setUp(): void
    {
        parent::setUp();
        $this->redis = $this->getRedis($this->app, 'phpredis');
    }

    /**
     * @throws BindingResolutionException
     */
    public function getRepository(): RedisBatchRepository
    {
        return new RedisBatchRepository(
            $this->app->make(BatchFactory::class),
            $this->redis,
            'redis_batches_test'
        );
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws \RedisException
     * @throws ContainerExceptionInterface
     * @throws BindingResolutionException
     */
    public function testStore()
    {
        // Data
        $batchName = 'Test batch';

        // Precondition
        $pendingBatch = new PendingBatch(
            $this->app->get(Container::class),
            new Collection
        );
        $pendingBatch->name($batchName);

        // Expect
        $expectedTotalJobs = 0;
        $expectedPendingJobs = 0;
        $expectedFailedJobs = 0;
        $expectedFailedJobIds = [];
        $expectedName = 'Test batch';

        // Action
        $batch = $this->getRepository()->store($pendingBatch);

        // Assert
        $this->assertNotNull($batch->id);
        $this->assertNotNull($batch->createdAt);
        $this->assertEquals($expectedTotalJobs, $batch->totalJobs);
        $this->assertEquals($expectedPendingJobs, $batch->pendingJobs);
        $this->assertEquals($expectedFailedJobs, $batch->failedJobs);
        $this->assertEquals($expectedFailedJobIds, $batch->failedJobIds);
        $this->assertEquals($expectedName, $batch->name);
        $this->assertNull($batch->cancelledAt);
        $this->assertNull($batch->finishedAt);
    }

    public function tearDown(): void
    {
        parent::tearDown();
        $this->redis->flushdb();
        $this->redis->disconnect();
    }
}
