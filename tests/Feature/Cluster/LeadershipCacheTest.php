<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\WorkerDistributor;
use Cbox\LaravelQueueAutoscale\Contracts\ClusterStoreContract;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Scaling\WorkloadStateTracker;
use Cbox\LaravelQueueAutoscale\Testing\FakeClusterStore;

/**
 * distributeClusterTarget caches the layout it chose, so a steady cluster is
 * not reshuffled every cycle. That cache is a leader's working memory.
 *
 * Losing the lease and winning it back means another manager placed workers in
 * between, so anything remembered from before describes a cluster that no
 * longer exists. The cache is only checked for feasibility, so a stale layout
 * that still sums to the right total was replayed wholesale — every host
 * churning its workers to match a ten-minute-old picture, for no change in
 * demand.
 *
 * These drive the real cycle rather than reimplementing the transition, so
 * they fail if the reset moves or is removed.
 */
beforeEach(function (): void {
    $this->store = new FakeClusterStore;
    app()->instance(ClusterStoreContract::class, $this->store);
});

function leaderDistributor(AutoscaleManager $manager): WorkerDistributor
{
    return (new ReflectionProperty($manager, 'distributor'))->getValue($manager);
}

function leaderCache(AutoscaleManager $manager): array
{
    return leaderDistributor($manager)->cachedPlacements();
}

function primeLeaderCache(AutoscaleManager $manager, bool $wasLeader): void
{
    leaderDistributor($manager)->seed(['queue:redis:exports' => ['a' => 10, 'b' => 10]]);
    (new ReflectionProperty($manager, 'wasLeader'))->setValue($manager, $wasLeader);
}

function noteLeadership(AutoscaleManager $manager, bool $isLeader): void
{
    (new ReflectionMethod($manager, 'noteLeadership'))->invoke($manager, $isLeader);
}

test('regaining the lease discards the layout from before', function (): void {
    $manager = app(AutoscaleManager::class);

    // This manager did not lead last cycle, and now wins the lease.
    primeLeaderCache($manager, wasLeader: false);

    noteLeadership($manager, isLeader: true);

    expect(leaderCache($manager))->not->toHaveKey('queue:redis:exports');
});

test('an uninterrupted leader keeps its layout', function (): void {
    // The cache exists to stop a steady cluster reshuffling every cycle, so
    // clearing it on every leader cycle would defeat its purpose.
    $manager = app(AutoscaleManager::class);

    primeLeaderCache($manager, wasLeader: true);

    noteLeadership($manager, isLeader: true);

    expect(leaderCache($manager))->toHaveKey('queue:redis:exports');
});

function leaderBreachState(AutoscaleManager $manager): WorkloadStateTracker
{
    return (new ReflectionProperty($manager, 'workloadState'))->getValue($manager);
}

test('regaining the lease replaces breach memory with what the last leader published', function (): void {
    $manager = app(AutoscaleManager::class);
    $state = leaderBreachState($manager);

    // Remembered from this manager's previous lease; both have since moved on.
    $state->setBreaching('redis:recovered', true);
    $state->setBreaching('redis:still-hot', false);

    $this->store->withSummary(['workloads' => [
        ['type' => 'queue', 'connection' => 'redis', 'name' => 'recovered', 'sla_status' => 'ok'],
        ['type' => 'queue', 'connection' => 'redis', 'name' => 'still-hot', 'sla_status' => 'breached'],
        ['type' => 'group', 'connection' => 'redis', 'name' => 'mail', 'sla_status' => 'breached'],
        ['type' => 'queue', 'connection' => 'sqs', 'name' => 'warm', 'sla_status' => 'warning'],
    ]]);
    (new ReflectionProperty($manager, 'wasLeader'))->setValue($manager, false);

    noteLeadership($manager, isLeader: true);

    expect($state->wasBreaching('redis:recovered'))->toBeFalse()
        ->and($state->wasBreaching('redis:still-hot'))->toBeTrue()
        ->and($state->wasBreaching('group:redis:mail'))->toBeTrue()
        ->and($state->wasBreaching('redis:mail'))->toBeFalse()
        ->and($state->wasBreaching('sqs:warm'))->toBeFalse();
});

test('a summary from a leader without sla_status is judged on the oldest job age', function (): void {
    $manager = app(AutoscaleManager::class);
    $state = leaderBreachState($manager);

    $this->store->withSummary(['workloads' => [
        ['type' => 'queue', 'connection' => 'redis', 'name' => 'late', 'oldest_job_age' => 45, 'sla_target_seconds' => 30],
        ['type' => 'queue', 'connection' => 'redis', 'name' => 'fine', 'oldest_job_age' => 5, 'sla_target_seconds' => 30],
        ['type' => 'queue', 'connection' => 'redis', 'name' => 'empty', 'oldest_job_age' => 0, 'sla_target_seconds' => 0],
    ]]);

    noteLeadership($manager, isLeader: true);

    expect($state->wasBreaching('redis:late'))->toBeTrue()
        ->and($state->wasBreaching('redis:fine'))->toBeFalse()
        ->and($state->wasBreaching('redis:empty'))->toBeFalse();
});

test('no summary or a malformed one leaves the new leader with no breaches', function (array $summary): void {
    $manager = app(AutoscaleManager::class);
    $state = leaderBreachState($manager);
    $state->setBreaching('redis:old', true);
    $this->store->withSummary($summary);

    noteLeadership($manager, isLeader: true);

    expect($state->wasBreaching('redis:old'))->toBeFalse();
})->with([
    'none' => [[]],
    'workloads not a list' => [['workloads' => 'nope']],
    'rows not arrays' => [['workloads' => ['nope', 3]]],
]);

test('an uninterrupted leader keeps its breach memory', function (): void {
    $manager = app(AutoscaleManager::class);
    $state = leaderBreachState($manager);
    $state->setBreaching('redis:hot', true);
    (new ReflectionProperty($manager, 'wasLeader'))->setValue($manager, true);

    noteLeadership($manager, isLeader: true);

    expect($state->wasBreaching('redis:hot'))->toBeTrue();
});
