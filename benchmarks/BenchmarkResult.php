<?php

declare(strict_types=1);

namespace Temant\HttpCore\Benchmarks;

/**
 * The outcome of a single {@see Benchmark::run()} call.
 *
 * Immutable and dependency-free on purpose - this is meant to be reusable
 * outside this repo too, so it doesn't assume anything beyond plain PHP.
 */
final readonly class BenchmarkResult
{
    public function __construct(
        public string $label,
        public int $iterations,
        public float $elapsedNs,
        public int $memoryBytes,
    ) {
    }

    public function nsPerOp(): float
    {
        return $this->elapsedNs / $this->iterations;
    }

    public function opsPerSec(): float
    {
        return 1_000_000_000 / $this->nsPerOp();
    }

    /**
     * Approximate heap growth per operation. Noisy for small/short-lived
     * objects (GC timing, allocator reuse) - treat as a rough signal, not
     * a precise measurement.
     */
    public function bytesPerOp(): float
    {
        return $this->memoryBytes / $this->iterations;
    }
}
