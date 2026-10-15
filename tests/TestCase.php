<?php

namespace Cbox\LaravelQueueAutoscale\Tests;

use Cbox\LaravelQueueAutoscale\LaravelQueueAutoscaleServiceProvider;
use Cbox\LaravelQueueAutoscale\Testing\InteractsWithAutoscaling;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    /**
     * The trait this package ships for its consumers. Composed here so our own
     * suite reaches for the same helpers a host application would — if one of
     * them is awkward to use from here, that is a defect in the trait, not a
     * reason to work around it in a spec.
     */
    use InteractsWithAutoscaling;

    protected function getPackageProviders($app)
    {
        return [
            LaravelQueueAutoscaleServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        $this->configureRedis();
    }

    /**
     * Point the `default` Redis connection at a cluster when
     * REDIS_CLUSTER_HOSTS_AND_PORTS is set, otherwise a single node, so the
     * redis-group specs run against both modes depending on the CI job.
     *
     * Without REDIS_AVAILABLE or an explicit REDIS_PORT the single node is a
     * port nothing listens on. Specs that probe for Redis then skip instead
     * of writing to whatever happens to run on 6379 on a developer machine.
     */
    private function configureRedis(): void
    {
        $clusterHosts = getenv('REDIS_CLUSTER_HOSTS_AND_PORTS');

        if (is_string($clusterHosts) && $clusterHosts !== '') {
            config()->set('database.redis', [
                'client' => 'phpredis',
                'options' => [
                    'cluster' => 'redis',
                    'prefix' => 'lqas_test_',
                ],
                'clusters' => [
                    'default' => array_map(
                        static fn (string $hostAndPort): array => [
                            'host' => explode(':', $hostAndPort)[0],
                            'port' => (int) explode(':', $hostAndPort)[1],
                        ],
                        explode(',', $clusterHosts),
                    ),
                ],
            ]);

            return;
        }

        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = getenv('REDIS_PORT') ?: (getenv('REDIS_AVAILABLE') ? '6379' : '1');

        config()->set('database.redis', [
            'client' => 'phpredis',
            'options' => [
                'prefix' => 'lqas_test_',
            ],
            'default' => [
                'host' => $host,
                'port' => (int) $port,
                'database' => 0,
                'timeout' => 1.0,
            ],
        ]);
    }
}
