<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    /**
     * List system and security audit logs with filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        // Audit logs are strictly reserved for Super Admins
        if (! $actor->isSuperAdmin()) {
            return $this->error('Only Super Admins can review system audit logs.', 403);
        }

        $query = AuditLog::where('company_id', $actor->company_id)
            ->with('user:id,name,email,role_id')
            ->orderByDesc('id');

        if ($request->filled('event')) {
            $query->where('event', $request->query('event'));
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->query('severity'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->query('user_id'));
        }

        if ($request->filled('search')) {
            $s = trim($request->query('search'));
            $query->where(function ($q) use ($s) {
                $q->where('description', 'like', "%{$s}%")
                    ->orWhere('event', 'like', "%{$s}%")
                    ->orWhere('ip_address', 'like', "%{$s}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        $perPage = min((int) ($request->query('per_page', 25)), 100);
        $logs = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'per_page'     => $logs->perPage(),
                'total'        => $logs->total(),
            ],
        ]);
    }

    /**
     * Stream a CSV export of filtered audit logs.
     */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $actor = $request->user();

        if (! $actor->isSuperAdmin()) {
            return $this->error('Only Super Admins can export system audit logs.', 403);
        }

        $query = AuditLog::where('company_id', $actor->company_id)
            ->with('user:id,name,email')
            ->orderByDesc('id');

        if ($request->filled('event')) {
            $query->where('event', $request->query('event'));
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->query('severity'));
        }

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="audit_logs_'.date('Y-m-d').'.csv"',
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        return response()->stream(function () use ($query) {
            $out = fopen('php://output', 'w');
            // Write UTF-8 BOM for Microsoft Excel
            fputs($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'ID',
                'Timestamp (UTC)',
                'Severity',
                'Event Tag',
                'Description',
                'User Name',
                'User Email',
                'IP Address',
                'Metadata',
            ]);

            $query->chunk(500, function ($records) use ($out) {
                foreach ($records as $log) {
                    fputcsv($out, [
                        $log->id,
                        $log->created_at->toIso8601String(),
                        strtoupper($log->severity),
                        $log->event,
                        $log->description,
                        $log->user?->name ?? 'System',
                        $log->user?->email ?? 'N/A',
                        $log->ip_address ?? 'N/A',
                        $log->metadata ? json_encode($log->metadata) : '',
                    ]);
                }
            });

            fclose($out);
        }, 200, $headers);
    }
}
