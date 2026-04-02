<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BaselineMetricsReport extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sync:baseline-report {--json= : Output file path for JSON report}';

    /**
     * @var string
     */
    protected $description = 'Generate baseline metrics (p50/p95/p99 approximation, error rate, queries/request and payload bytes)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $endpoints = Cache::get('metrics:endpoints', []);

        if (empty($endpoints)) {
            $this->warn('No metrics captured yet. Exercise endpoints first, then re-run this command.');

            return self::SUCCESS;
        }

        $rows = [];
        $report = [
            'generated_at' => now()->toIso8601String(),
            'system' => $this->systemMetrics(),
            'endpoints' => [],
        ];

        foreach ($endpoints as $endpoint) {
            $prefix = 'metrics:endpoint:' . $endpoint;
            $count = (int) Cache::get($prefix . ':count', 0);
            if ($count === 0) {
                continue;
            }

            $errors = (int) Cache::get($prefix . ':errors', 0);
            $avgQueries = round(((int) Cache::get($prefix . ':queries_total', 0)) / $count, 2);
            $avgBytes = round(((int) Cache::get($prefix . ':bytes_total', 0)) / $count, 2);
            $hist = [
                '<=50' => (int) Cache::get($prefix . ':hist:<=50', 0),
                '<=100' => (int) Cache::get($prefix . ':hist:<=100', 0),
                '<=250' => (int) Cache::get($prefix . ':hist:<=250', 0),
                '<=500' => (int) Cache::get($prefix . ':hist:<=500', 0),
                '<=1000' => (int) Cache::get($prefix . ':hist:<=1000', 0),
                '>1000' => (int) Cache::get($prefix . ':hist:>1000', 0),
            ];

            $p50 = $this->percentileFromHistogram($hist, $count, 0.50);
            $p95 = $this->percentileFromHistogram($hist, $count, 0.95);
            $p99 = $this->percentileFromHistogram($hist, $count, 0.99);
            $errorRate = round(($errors / $count) * 100, 2);

            $rows[] = [
                $endpoint,
                $count,
                $p50,
                $p95,
                $p99,
                $avgQueries,
                $avgBytes,
                $errorRate . '%',
            ];

            $report['endpoints'][$endpoint] = [
                'count' => $count,
                'p50_ms_approx' => $p50,
                'p95_ms_approx' => $p95,
                'p99_ms_approx' => $p99,
                'avg_queries_per_request' => $avgQueries,
                'avg_response_bytes' => $avgBytes,
                'error_rate_percent' => $errorRate,
                'histogram' => $hist,
            ];
        }

        $this->table(
            ['Endpoint', 'Count', 'p50(ms)', 'p95(ms)', 'p99(ms)', 'Avg Queries', 'Avg Bytes', 'Error Rate'],
            $rows,
        );

        if ($jsonPath = $this->option('json')) {
            file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info('JSON report written to ' . $jsonPath);
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, int> $hist
     */
    private function percentileFromHistogram(array $hist, int $totalCount, float $percentile): int
    {
        $threshold = (int) ceil($totalCount * $percentile);
        $running = 0;

        $map = [
            '<=50' => 50,
            '<=100' => 100,
            '<=250' => 250,
            '<=500' => 500,
            '<=1000' => 1000,
            '>1000' => 1500,
        ];

        foreach ($map as $bucket => $upperBound) {
            $running += $hist[$bucket] ?? 0;
            if ($running >= $threshold) {
                return $upperBound;
            }
        }

        return 1500;
    }

    /**
     * @return array<string, mixed>
     */
    private function systemMetrics(): array
    {
        $dbConnections = null;
        try {
            $row = DB::selectOne('SELECT count(*) as cnt FROM pg_stat_activity WHERE datname = current_database()');
            $dbConnections = isset($row->cnt) ? (int) $row->cnt : null;
        } catch (\Throwable) {
            $dbConnections = null;
        }

        return [
            'cpu_load_1m' => sys_getloadavg()[0] ?? null,
            'php_memory_peak_bytes' => memory_get_peak_usage(true),
            'db_connections' => $dbConnections,
        ];
    }
}
