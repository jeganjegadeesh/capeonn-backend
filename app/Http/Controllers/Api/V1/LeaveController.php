<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Leaves\LeaveApplyRequest;
use App\Http\Requests\Leaves\LeaveTypeRequest;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessControl;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaveController extends Controller
{
    public function __construct(private AccessControl $access)
    {
    }

    /** GET /leaves/types */
    public function types(Request $request): JsonResponse
    {
        $types = LeaveType::where('company_id', $request->user()->company_id)
            ->where('is_active', true)
            ->get();

        return $this->success($types);
    }

    /** POST /leaves/types */
    public function storeType(LeaveTypeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['company_id'] = $request->user()->company_id;

        $type = LeaveType::create($data);

        return $this->success($type, 'Leave type created', 201);
    }

    /** PUT /leaves/types/{id} */
    public function updateType(LeaveTypeRequest $request, int $id): JsonResponse
    {
        $type = LeaveType::where('company_id', $request->user()->company_id)->find($id);
        if (! $type) {
            return $this->error('Leave type not found', 404);
        }

        $type->update($request->validated());

        return $this->success($type, 'Leave type updated');
    }

    /** GET /leaves/balances?year=&user_id= */
    public function balances(Request $request): JsonResponse
    {
        $actor = $request->user();
        $targetId = $actor->id;

        if ($request->filled('user_id') && (int) $request->query('user_id') !== (int) $actor->id) {
            $targetUser = $this->access->constrainUsers(User::query(), $actor, 'leave.view')
                ->find((int) $request->query('user_id'));

            if (! $targetUser) {
                return $this->error('Employee not found or access denied', 404);
            }
            $targetId = $targetUser->id;
        }

        $target = $request->filled('user_id') && (int) $request->query('user_id') !== (int) $actor->id
            ? ($targetUser ?? User::find($targetId))
            : $actor;

        if ($target && $target->isSuperAdmin()) {
            return $this->success([]);
        }

        $year = (int) $request->query('year', now()->year);

        // Ensure balances exist for all active leave types
        $types = LeaveType::where('company_id', $actor->company_id)->where('is_active', true)->get();
        foreach ($types as $type) {
            LeaveBalance::firstOrCreate(
                ['user_id' => $targetId, 'leave_type_id' => $type->id, 'year' => $year],
                [
                    'company_id'   => $actor->company_id,
                    'total_days'   => $type->annual_days,
                    'used_days'    => 0,
                    'pending_days' => 0,
                ]
            );
        }

        $balances = LeaveBalance::where('user_id', $targetId)
            ->where('year', $year)
            ->with('leaveType')
            ->get()
            ->map(fn ($b) => [
                'id'             => $b->id,
                'leave_type_id'  => $b->leave_type_id,
                'name'           => $b->leaveType?->name,
                'code'           => $b->leaveType?->code,
                'is_paid'        => (bool) $b->leaveType?->is_paid,
                'year'           => $b->year,
                'total_days'     => (float) $b->total_days,
                'used_days'      => (float) $b->used_days,
                'pending_days'   => (float) $b->pending_days,
                'remaining_days' => (float) $b->remainingDays(),
            ]);

        return $this->success($balances);
    }

    /** GET /leaves/my-requests */
    public function myRequests(Request $request): JsonResponse
    {
        $actor = $request->user();

        $paginator = LeaveRequest::where('user_id', $actor->id)
            ->with(['leaveType', 'approver:id,name', 'actionedBy:id,name'])
            ->orderByDesc('start_date')
            ->paginate($this->perPage($request));

        $items = $paginator->getCollection()->map(fn ($r) => $this->formatRequest($r))->values()->all();

        return $this->paginated($paginator, $items);
    }

    /** POST /leaves/requests */
    public function storeRequest(LeaveApplyRequest $request): JsonResponse
    {
        $actor = $request->user();

        if ($actor->isSuperAdmin()) {
            return $this->error('Super Admin accounts cannot apply for leave', 403);
        }

        $data = $request->validated();

        $start = Carbon::parse($data['start_date']);
        $end = Carbon::parse($data['end_date']);
        $isHalfDay = ! empty($data['is_half_day']);

        if ($isHalfDay) {
            $daysCount = 0.5;
        } else {
            $daysCount = (float) ($start->diffInDays($end) + 1);
        }

        $type = LeaveType::where('company_id', $actor->company_id)->find($data['leave_type_id']);
        if (! $type) {
            return $this->error('Invalid leave type', 422);
        }

        $year = $start->year;
        $balance = LeaveBalance::firstOrCreate(
            ['user_id' => $actor->id, 'leave_type_id' => $type->id, 'year' => $year],
            [
                'company_id'   => $actor->company_id,
                'total_days'   => $type->annual_days,
                'used_days'    => 0,
                'pending_days' => 0,
            ]
        );

        if ($type->is_paid && $balance->remainingDays() < $daysCount) {
            return $this->error(
                sprintf('Insufficient leave balance. Remaining: %.1f, Requested: %.1f', $balance->remainingDays(), $daysCount),
                422
            );
        }

        $approverInfo = $this->resolveApprover($actor);

        return DB::transaction(function () use ($actor, $data, $daysCount, $isHalfDay, $balance, $approverInfo) {
            $leaveRequest = LeaveRequest::create([
                'user_id'         => $actor->id,
                'company_id'      => $actor->company_id,
                'leave_type_id'   => $data['leave_type_id'],
                'start_date'      => $data['start_date'],
                'end_date'        => $data['end_date'],
                'is_half_day'     => $isHalfDay,
                'half_day_type'   => $data['half_day_type'] ?? null,
                'days_count'      => $daysCount,
                'reason'          => $data['reason'],
                'attachment_path' => $data['attachment_path'] ?? null,
                'status'          => 'pending',
                'approver_id'     => $approverInfo['approver_id'],
                'final_approver'  => $approverInfo['final_approver'],
            ]);

            $balance->increment('pending_days', $daysCount);

            return $this->success(
                $this->formatRequest($leaveRequest->load(['leaveType', 'approver'])),
                'Leave application submitted successfully',
                201
            );
        });
    }

    /** PUT /leaves/requests/{id}/cancel */
    public function cancelRequest(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $leave = LeaveRequest::where('user_id', $actor->id)->find($id);

        if (! $leave) {
            return $this->error('Leave request not found', 404);
        }

        if ($leave->status !== 'pending') {
            return $this->error("Cannot cancel request with status: {$leave->status}", 422);
        }

        return DB::transaction(function () use ($leave) {
            $leave->update(['status' => 'cancelled']);

            $year = Carbon::parse($leave->start_date)->year;
            $balance = LeaveBalance::where('user_id', $leave->user_id)
                ->where('leave_type_id', $leave->leave_type_id)
                ->where('year', $year)
                ->first();

            if ($balance) {
                $balance->decrement('pending_days', min((float)$balance->pending_days, (float)$leave->days_count));
            }

            return $this->success($this->formatRequest($leave->fresh(['leaveType', 'approver'])), 'Leave request cancelled');
        });
    }

    /** GET /leaves/requests (Manager/HR/Approver review queue) */
    public function requests(Request $request): JsonResponse
    {
        $actor = $request->user();

        $query = $this->access->constrainByUserId(
            LeaveRequest::query()->with([
                'user:id,name,email,employee_code,department_id',
                'user.department:id,name',
                'leaveType',
                'approver:id,name',
                'actionedBy:id,name',
            ]),
            $actor,
            'leave.view'
        );

        // Show requests assigned to the logged-in approver (unless Super Admin or explicitly requesting all)
        if ($request->boolean('assigned_only', true) && ! $actor->isSuperAdmin()) {
            if ($actor->isHR()) {
                // HR sees requests assigned directly to HR or requests where user is a Manager
                $query->where(function ($q) use ($actor) {
                    $q->where('leave_requests.approver_id', $actor->id)
                      ->orWhereHas('user.role', fn ($r) => $r->where('slug', Role::MANAGER));
                });
            } else {
                $query->where('leave_requests.approver_id', $actor->id);
            }
        }

        if ($request->filled('status')) {
            $query->where('leave_requests.status', $request->query('status'));
        }
        if ($request->filled('leave_type_id')) {
            $query->where('leave_requests.leave_type_id', (int) $request->query('leave_type_id'));
        }
        if ($request->filled('department_id')) {
            $deptId = (int) $request->query('department_id');
            $query->whereHas('user', fn ($u) => $u->where('department_id', $deptId));
        }

        $paginator = $query->orderByDesc('leave_requests.created_at')->paginate($this->perPage($request));
        $items = $paginator->getCollection()->map(fn ($r) => $this->formatRequest($r))->values()->all();

        return $this->paginated($paginator, $items);
    }

    /** PUT /leaves/requests/{id}/approve */
    public function approveRequest(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $leave = LeaveRequest::with(['user', 'leaveType'])->find($id);

        if (! $leave || (int) $leave->company_id !== (int) $actor->company_id) {
            return $this->error('Leave request not found', 404);
        }

        // Stop a user from approving their own leave
        if ((int) $leave->user_id === (int) $actor->id) {
            return $this->error('You cannot approve your own leave request', 403);
        }

        if (! $this->access->canAccessUser($actor, $leave->user, 'leave.approve') && (int) $leave->approver_id !== (int) $actor->id) {
            return $this->error('You do not have permission to approve this leave request', 403);
        }

        if ($leave->status !== 'pending') {
            return $this->error("Cannot approve request with status: {$leave->status}", 422);
        }

        return DB::transaction(function () use ($leave, $actor, $request) {
            $leave->update([
                'status'         => 'approved',
                'actioned_by_id' => $actor->id,
                'action_remarks' => $request->input('remarks'),
            ]);

            $year = Carbon::parse($leave->start_date)->year;
            $balance = LeaveBalance::where('user_id', $leave->user_id)
                ->where('leave_type_id', $leave->leave_type_id)
                ->where('year', $year)
                ->first();

            if ($balance) {
                $days = (float) $leave->days_count;
                $balance->decrement('pending_days', min((float)$balance->pending_days, $days));
                $balance->increment('used_days', $days);
            }

            return $this->success($this->formatRequest($leave->fresh(['user', 'leaveType', 'approver', 'actionedBy'])), 'Leave request approved');
        });
    }

    /** PUT /leaves/requests/{id}/reject */
    public function rejectRequest(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $leave = LeaveRequest::with(['user', 'leaveType'])->find($id);

        if (! $leave || (int) $leave->company_id !== (int) $actor->company_id) {
            return $this->error('Leave request not found', 404);
        }

        // Stop a user from rejecting their own leave (use cancel instead)
        if ((int) $leave->user_id === (int) $actor->id) {
            return $this->error('You cannot reject your own leave request. Use cancel instead.', 403);
        }

        if (! $this->access->canAccessUser($actor, $leave->user, 'leave.approve') && (int) $leave->approver_id !== (int) $actor->id) {
            return $this->error('You do not have permission to reject this leave request', 403);
        }

        if ($leave->status !== 'pending') {
            return $this->error("Cannot reject request with status: {$leave->status}", 422);
        }

        return DB::transaction(function () use ($leave, $actor, $request) {
            $leave->update([
                'status'         => 'rejected',
                'actioned_by_id' => $actor->id,
                'action_remarks' => $request->input('remarks', 'Rejected by approver'),
            ]);

            $year = Carbon::parse($leave->start_date)->year;
            $balance = LeaveBalance::where('user_id', $leave->user_id)
                ->where('leave_type_id', $leave->leave_type_id)
                ->where('year', $year)
                ->first();

            if ($balance) {
                $balance->decrement('pending_days', min((float)$balance->pending_days, $days = (float) $leave->days_count));
            }

            return $this->success($this->formatRequest($leave->fresh(['user', 'leaveType', 'approver', 'actionedBy'])), 'Leave request rejected');
        });
    }

    /**
     * Approver logic:
     * Employee -> Team Lead
     * Team Lead -> Manager
     * Manager -> HR
     * HR -> Director / Senior Manager (final approver)
     */
    private function resolveApprover(User $applicant): array
    {
        $roleSlug = $applicant->role?->slug;

        // 1. Employee -> Team Lead
        if ($roleSlug === Role::EMPLOYEE) {
            if ($applicant->reportsTo && $applicant->reportsTo->role?->slug === Role::TEAM_LEAD) {
                return ['approver_id' => $applicant->reports_to_id, 'final_approver' => false];
            }
            $tl = User::where('company_id', $applicant->company_id)
                ->where('department_id', $applicant->department_id)
                ->whereHas('role', fn ($q) => $q->where('slug', Role::TEAM_LEAD))
                ->where('is_active', true)
                ->first();
            if ($tl) {
                return ['approver_id' => $tl->id, 'final_approver' => false];
            }
            if ($applicant->reports_to_id) {
                return ['approver_id' => $applicant->reports_to_id, 'final_approver' => false];
            }
        }

        // 2. Team Lead -> Manager
        if ($roleSlug === Role::TEAM_LEAD) {
            if ($applicant->reportsTo && $applicant->reportsTo->role?->slug === Role::MANAGER) {
                return ['approver_id' => $applicant->reports_to_id, 'final_approver' => false];
            }
            $mgr = User::where('company_id', $applicant->company_id)
                ->where('department_id', $applicant->department_id)
                ->whereHas('role', fn ($q) => $q->where('slug', Role::MANAGER))
                ->where('is_active', true)
                ->first();
            if ($mgr) {
                return ['approver_id' => $mgr->id, 'final_approver' => false];
            }
            if ($applicant->reports_to_id) {
                return ['approver_id' => $applicant->reports_to_id, 'final_approver' => false];
            }
        }

        // 3. Manager -> HR
        if ($roleSlug === Role::MANAGER) {
            $hr = User::where('company_id', $applicant->company_id)
                ->whereHas('role', fn ($q) => $q->where('slug', Role::HR))
                ->where('is_active', true)
                ->first();
            if ($hr) {
                return ['approver_id' => $hr->id, 'final_approver' => false];
            }
        }

        // 4. HR -> Director / senior Manager (final approver)
        if ($roleSlug === Role::HR) {
            if ($applicant->reports_to_id) {
                return ['approver_id' => $applicant->reports_to_id, 'final_approver' => true];
            }
            $senior = User::where('company_id', $applicant->company_id)
                ->where('id', '!=', $applicant->id)
                ->whereHas('role', fn ($q) => $q->where('slug', Role::MANAGER))
                ->where('is_active', true)
                ->first();
            if ($senior) {
                return ['approver_id' => $senior->id, 'final_approver' => true];
            }
        }

        $fallback = $applicant->reports_to_id
            ?? User::where('company_id', $applicant->company_id)
                ->where('id', '!=', $applicant->id)
                ->whereHas('role', fn ($q) => $q->whereIn('slug', [Role::HR, Role::MANAGER, Role::SUPER_ADMIN, 'admin']))
                ->value('id');

        return ['approver_id' => $fallback, 'final_approver' => false];
    }

    private function formatRequest(LeaveRequest $r): array
    {
        return [
            'id'              => $r->id,
            'user_id'         => $r->user_id,
            'user_name'       => $r->user?->name,
            'employee_code'   => $r->user?->employee_code,
            'department_name' => $r->user?->department?->name,
            'leave_type_id'   => $r->leave_type_id,
            'leave_type_name' => $r->leaveType?->name,
            'leave_type_code' => $r->leaveType?->code,
            'start_date'      => $r->start_date->toDateString(),
            'end_date'        => $r->end_date->toDateString(),
            'is_half_day'     => (bool) $r->is_half_day,
            'half_day_type'   => $r->half_day_type,
            'days_count'      => (float) $r->days_count,
            'reason'          => $r->reason,
            'status'          => $r->status,
            'approver_id'     => $r->approver_id,
            'approver_name'   => $r->approver?->name,
            'final_approver'  => (bool) $r->final_approver,
            'actioned_by'     => $r->actionedBy ? ['id' => $r->actionedBy->id, 'name' => $r->actionedBy->name] : null,
            'action_remarks'  => $r->action_remarks,
            'attachment_path' => $r->attachment_path,
            'created_at'      => $r->created_at->toIso8601String(),
        ];
    }
}
