<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\StoreHistoryRequest;
use App\Models\EmployeeHistory;
use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeHistoryController extends Controller
{
    public function __construct(private AccessControl $access)
    {
    }

    public function index(Request $request, int $employee): JsonResponse
    {
        $actor = $request->user();
        $target = $this->access->constrainUsers(User::query(), $actor, 'employees.view')->find($employee);

        if (! $target) {
            return $this->error('Employee not found or access denied', 404);
        }

        $histories = EmployeeHistory::where('user_id', $target->id)
            ->with('performedBy:id,name')
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($h) => [
                'id'             => $h->id,
                'user_id'        => $h->user_id,
                'event_type'     => $h->event_type,
                'title'          => $h->title,
                'description'    => $h->description,
                'metadata'       => $h->metadata,
                'effective_date' => $h->effective_date->toDateString(),
                'performed_by'   => $h->performedBy ? ['id' => $h->performedBy->id, 'name' => $h->performedBy->name] : null,
                'created_at'     => $h->created_at->toIso8601String(),
            ]);

        return $this->success($histories);
    }

    public function store(StoreHistoryRequest $request, int $employee): JsonResponse
    {
        return $this->error('Employee history is system-generated and read-only', 403);
    }
}
