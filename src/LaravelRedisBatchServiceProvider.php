<?php

namespace JamesBuzz\RedisBatchDriver;

use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;
use Illuminate\Support\ServiceProvider;
use JamesBuzz\RedisBatchDriver\Repositories\RedisBatchRepository;

class LaravelRedisBatchServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot() {}

    /**
     * Register the application services.
     */
    public function register(): void
    {
        $this->app->singleton(RedisBatchRepository::class, function ($app) {
            return new RedisBatchRepository(
                $app->make(BatchFactory::class),
                $app->make('redis')->connection($app->config->get('queue.batching.redis_connection', 'default')),
                $app->config->get('queue.batching.table', 'job_batches')
            );
        });

        $batchDriver = $this->app['config']->get('queue.batching.driver', 'database');

        if ($batchDriver === 'redis') {
            $this->app->singleton(BatchRepository::class, RedisBatchRepository::class);
        }
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        $batchDriver = $this->app['config']->get('queue.batching.driver', 'database');

        if ($batchDriver === 'redis') {
            return [
                BatchRepository::class,
                RedisBatchRepository::class,
            ];
        }

        return [];
    }
}
