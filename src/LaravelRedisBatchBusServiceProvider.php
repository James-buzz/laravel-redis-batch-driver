<?php

namespace JamesBuzz\RedisBatchDriver;

use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\BusServiceProvider;
use JamesBuzz\RedisBatchDriver\Repositories\RedisBatchRepository;

class LaravelRedisBatchBusServiceProvider extends BusServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot() {}

    /**
     * Register the application services.
     */
    protected function registerBatchServices()
    {
        $batchDriver = $this->app['config']->get('queue.batching.driver', 'database');

        if ($batchDriver === 'redis') {
            $this->app->singleton(BatchRepository::class, function ($app) {
                $factory  = $app->make(BatchFactory::class);
                return new RedisBatchRepository(
                    $factory,
                    $app->make('redis')->connection($app->config->get('queue.batching.redis_connection', 'default')),
                    $app->config->get('queue.batching.table', 'job_batches')
                );
            });
        } else {
            parent::registerBatchServices();
        }
    }
}
