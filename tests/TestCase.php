<?php

namespace JamesBuzz\RedisBatchDriver\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Env;

class TestCase extends \Orchestra\Testbench\TestCase
{
    public function setUp(): void
    {
        parent::setUp();
    }

    protected function getRedis(Application $app, string $driver): PhpRedisConnection|PredisConnection
    {
        $host = Env::get('REDIS_HOST', 'redis');
        $port = Env::get('REDIS_PORT', 6379);

        /** @var PhpRedisConnection|PredisConnection $connection */
        $connection = (new RedisManager($app, $driver, [
            'cluster' => false,
            'options' => [
                'prefix' => 'laravel_redis_batch_driver:',
            ],
            'default' => [
                'host' => $host,
                'port' => $port,
                'database' => 3,
                'timeout' => 1,
                'name' => 'default',
            ],
        ]))->connection();

        return $connection;
    }

    protected function dumpRedisDataForDebug($connection)
    {
        $keys = $connection->keys('*');

        $data = [];
        foreach ($keys as $key) {
            $type = $connection->type($key);

            switch ($type) {
                case 'string':
                    $data[$key] = $connection->get($key);
                    break;
                case 'list':
                    $data[$key] = $connection->lrange($key, 0, -1);
                    break;
                case 'set':
                    $data[$key] = $connection->smembers($key);
                    break;
                case 'hash':
                    $data[$key] = $connection->hgetall($key);
                    break;
                case 'zset':
                    $data[$key] = $connection->zrange($key, 0, -1, ['withscores' => true]);
                    break;
                default:
                    $data[$key] = 'Unknown type';
            }
        }

        return $data;
    }

    protected function getEnvironmentSetUp($app): void {}
}
