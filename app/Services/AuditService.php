<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditService
{
    /**
     * Record a system or administrative audit entry.
     */
    public function log(
        int $companyId,
        ?int $userId,
        string $event,
        string $description,
        ?array $metadata = null,
        string $severity = 'info',
        ?Request $request = null
    ): AuditLog {
        $ip = $request ? $request->ip() : request()->ip();
        $ua = $request ? $request->userAgent() : request()->userAgent();

        return AuditLog::create([
            'company_id'  => $companyId,
            'user_id'     => $userId,
            'event'       => $event,
            'description' => $description,
            'ip_address'  => $ip,
            'user_agent'  => $ua ? substr($ua, 0, 500) : null,
            'severity'    => $severity,
            'metadata'    => $metadata,
            'created_at'  => now(),
        ]);
    }

    /**
     * Helper to log authentication events.
     */
    public function logAuth(User $user, string $event, string $description, ?Request $request = null): AuditLog
    {
        return $this->log(
            companyId: $user->company_id,
            userId: $user->id,
            event: $event,
            description: $description,
            metadata: ['email' => $user->email, 'role' => $user->role?->slug],
            severity: 'info',
            request: $request
        );
    }

    /**
     * Helper to log security anomalies and sensitive actions.
     */
    public function logSecurity(
        int $companyId,
        ?int $userId,
        string $event,
        string $description,
        ?array $metadata = null,
        string $severity = 'warning',
        ?Request $request = null
    ): AuditLog {
        return $this->log(
            companyId: $companyId,
            userId: $userId,
            event: $event,
            description: $description,
            metadata: $metadata,
            severity: $severity,
            request: $request
        );
    }
}
