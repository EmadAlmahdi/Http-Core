<?php

declare(strict_types=1);

namespace Temant\HttpCore\Benchmarks;

/**
 * A tiny, dependency-free microbenchmark runner.
 *
 * This is not a testing tool - there are no assertions, and nothing here
 * fails a build. It exists to turn a callable into a reproducible
 * ns/op + ops/sec (and a rough bytes/op) number, with a warm-up phase and
 * garbage-collection held off during the timed section so one unlucky GC
 * pause doesn't skew a run.
 *
 * It's intentionally standalone (no dependency on anything in `Src/`) so
 * you can copy it into another project's `benchmarks/` directory as-is.
 *
 * Usage:
 *
 *     $bench = new Benchmark();
 *     $result = $bench->run('Uri: construct', 100_000, function (): void {
 *         new Uri('https://example.com');
 *     });
 *     Benchmark::reportTable($bench->results());
 */
final class Benchmark
{
    /** @var BenchmarkResult[] */
    private array $results = [];

    public function __construct(
        private readonly int $warmupIterations = 1_000,
    ) {
    }

    /**
     * Runs `$fn` `$iterations` times and records the result.
     *
     * @param callable(): void $fn
     */
    public function run(string $label, int $iterations, callable $fn): BenchmarkResult
    {
        for ($i = 0; $i < $this->warmupIterations; $i++) {
            $fn();
        }

        $gcWasEnabled = gc_enabled();
        gc_collect_cycles();
        gc_disable();

        $memoryBefore = memory_get_usage();
        $start = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $fn();
        }

        $elapsedNs = hrtime(true) - $start;
        $memoryDelta = memory_get_usage() - $memoryBefore;

        if ($gcWasEnabled) {
            gc_enable();
        }

        $result = new BenchmarkResult($label, $iterations, (float) $elapsedNs, max(0, $memoryDelta));
        $this->results[] = $result;

        return $result;
    }

    /**
     * @return BenchmarkResult[]
     */
    public function results(): array
    {
        return $this->results;
    }

    /**
     * Prints one or more named groups of results, each as its own
     * fastest-first table - handy for comparing several implementations
     * of the same operation side by side.
     *
     * @param array<string, BenchmarkResult[]> $groups
     */
    public static function reportGroups(array $groups): void
    {
        foreach ($groups as $groupLabel => $results) {
            echo "\n{$groupLabel}\n" . str_repeat('-', mb_strlen($groupLabel)) . "\n";
            self::reportTable($results);
        }
    }

    /**
     * Prints a fastest-first table for a single set of results, with each
     * row's speed relative to the fastest one in the set.
     *
     * @param BenchmarkResult[] $results
     */
    public static function reportTable(array $results): void
    {
        if ($results === []) {
            return;
        }

        $sorted = $results;
        usort($sorted, static fn(BenchmarkResult $a, BenchmarkResult $b) => $a->nsPerOp() <=> $b->nsPerOp());

        $fastestNsPerOp = $sorted[0]->nsPerOp();

        foreach ($sorted as $result) {
            $relative = $result->nsPerOp() / $fastestNsPerOp;

            \printf(
                "  %-28s %10s ops  %9.1f ns/op  %12s ops/sec  %5.2fx\n",
                $result->label,
                number_format($result->iterations),
                $result->nsPerOp(),
                number_format($result->opsPerSec(), 0),
                $relative
            );
        }
    }

    /**
     * A human-readable warning if Xdebug is loaded in a mode that adds
     * per-call overhead, or null if timings can be trusted as-is.
     *
     * Xdebug's step-debugging/coverage/profiling hooks run on every
     * function call and can slow execution down by an order of magnitude
     * or more - easy to forget it's enabled, and it silently invalidates
     * any timing comparison if it is.
     */
    public static function xdebugWarning(): ?string
    {
        if (!\extension_loaded('xdebug')) {
            return null;
        }

        $modes = \function_exists('xdebug_info') ? (array) xdebug_info('mode') : array_filter([(string) ini_get('xdebug.mode')]);
        $activeModes = array_diff($modes, ['off', '']);

        if ($activeModes === []) {
            return null;
        }

        return \sprintf(
            'Xdebug is active (mode: %s) - timings below include its overhead and are not representative. Re-run with XDEBUG_MODE=off for real numbers.',
            implode(',', $activeModes)
        );
    }
}
