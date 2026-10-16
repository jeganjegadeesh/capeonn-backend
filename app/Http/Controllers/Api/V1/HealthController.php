<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HealthController extends Controller
{
    /**
     * Check system health, connectivity, storage writability, and queue backlog.
     * Public diagnostic endpoint suitable for load balancers and uptime monitoring.
     */
    public function check(Request $request): JsonResponse
    {
        $checks = [];
        $isHealthy = true;

        // 1. Database Connectivity & Query Latency
        $dbStart = microtime(true);
        try {
            DB::connection()->getPdo();
            $dbLatencyMs = round((microtime(true) - $dbStart) * 1000, 2);
            $checks['database'] = [
                'status' => 'ok',
                'latency_ms' => $dbLatencyMs,
                'connection' => config('database.default'),
            ];
        } catch (\Throwable $e) {
            $isHealthy = false;
            $checks['database'] = [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }

        // 2. Cache Store Operability
        $cacheStart = microtime(true);
        try {
            $testKey = 'health_ping_' . uniqid();
            Cache::put($testKey, 'ok', 10);
            $retrieved = Cache::get($testKey);
            Cache::forget($testKey);

            $cacheLatencyMs = round((microtime(true) - $cacheStart) * 1000, 2);
            $checks['cache'] = [
                'status' => $retrieved === 'ok' ? 'ok' : 'degraded',
                'latency_ms' => $cacheLatencyMs,
                'store' => config('cache.default'),
            ];
        } catch (\Throwable $e) {
            $checks['cache'] = [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }

        // 3. Storage Disk Writability
        try {
            $storagePath = storage_path('app');
            $isWritable = is_writable($storagePath);
            $checks['storage'] = [
                'status' => $isWritable ? 'ok' : 'error',
                'path' => 'storage/app',
                'writable' => $isWritable,
            ];
            if (! $isWritable) {
                $isHealthy = false;
            }
        } catch (\Throwable $e) {
            $isHealthy = false;
            $checks['storage'] = [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }

        // 4. Queue / Failed Jobs Diagnostic
        try {
            $hasJobsTable = Schema::hasTable('jobs');
            $hasFailedTable = Schema::hasTable('failed_jobs');

            $pendingJobs = $hasJobsTable ? DB::table('jobs')->count() : 0;
            $failedJobs = $hasFailedTable ? DB::table('failed_jobs')->count() : 0;

            $checks['queue'] = [
                'status' => 'ok',
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobs,
                'driver' => config('queue.default'),
            ];
        } catch (\Throwable $e) {
            $checks['queue'] = [
                'status' => 'unknown',
                'message' => $e->getMessage(),
            ];
        }

        $httpStatus = $isHealthy ? 200 : 503;

        return response()->json([
            'status' => $isHealthy ? 'healthy' : 'unhealthy',
            'timestamp' => now()->toIso8601String(),
            'environment' => config('app.env'),
            'version' => '1.0.0',
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'checks' => $checks,
        ], $httpStatus);
    }
}
