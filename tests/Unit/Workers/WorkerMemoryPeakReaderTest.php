<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Workers\WorkerMemoryPeakReader;

beforeEach(function (): void {
    $this->procRoot = sys_get_temp_dir().'/autoscale-proc-'.bin2hex(random_bytes(4));
    mkdir("{$this->procRoot}/4242", recursive: true);
});

afterEach(function (): void {
    @unlink("{$this->procRoot}/4242/status");
    @rmdir("{$this->procRoot}/4242");
    @rmdir($this->procRoot);
});

test('reads a process\'s peak resident memory from VmHWM', function (): void {
    file_put_contents("{$this->procRoot}/4242/status", "Name:\tphp\nVmPeak:\t  900000 kB\nVmHWM:\t  262144 kB\nVmRSS:\t  131072 kB\n");

    expect((new WorkerMemoryPeakReader($this->procRoot))->peakMb(4242))->toBe(256.0);
});

test('returns null for a process it cannot read', function (): void {
    expect((new WorkerMemoryPeakReader($this->procRoot))->peakMb(9999))->toBeNull();
});

test('returns null when the status file has no VmHWM line', function (): void {
    file_put_contents("{$this->procRoot}/4242/status", "Name:\tphp\nVmRSS:\t  131072 kB\n");

    expect((new WorkerMemoryPeakReader($this->procRoot))->peakMb(4242))->toBeNull();
});
