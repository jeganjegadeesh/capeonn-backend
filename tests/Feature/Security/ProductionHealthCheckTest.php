<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class ProductionHealthCheckTest extends TestCase
{
    /**
     * Test public health probe at /api/health.
     */
    public function test_public_health_probe_returns_healthy_status(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'timestamp',
                'environment',
                'version',
                'php_version',
                'laravel_version',
                'checks' => [
                    'database' => ['status'],
                    'cache' => ['status'],
                    'storage' => ['status', 'path', 'writable'],
                    'queue' => ['status', 'pending_jobs', 'failed_jobs', 'driver'],
                ],
            ]);

        $this->assertEquals('healthy', $response->json('status'));
        $this->assertEquals('ok', $response->json('checks.database.status'));
        $this->assertEquals('ok', $response->json('checks.cache.status'));
        $this->assertTrue($response->json('checks.storage.writable'));
    }

    /**
     * Test versioned health probe at /api/v1/health.
     */
    public function test_v1_versioned_health_probe_returns_healthy_status(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('checks.database.status', 'ok');
    }

    /**
     * Test health probe does not require authentication token.
     */
    public function test_health_probe_is_accessible_without_auth(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->get('/api/health');

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('timestamp'));
        $this->assertIsNumeric($response->json('checks.database.latency_ms'));
    }
}
