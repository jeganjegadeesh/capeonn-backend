<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\RegularizeRequest;
use App\Models\Attendance;
use App\Models\AttendanceRegularization;
use App\Models\User;
use App\Services\AccessControl;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceRegularizationController extends Controller
{
    public function __construct(private AccessControl $access)
    {
    }

    /** GET /attendance/regularizations */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        if ($actor->hasPermission('attendance.manage')) {
            $query = $this->access->constrainByUserId(
                AttendanceRegularization::query()
                    ->whereHas('user', fn ($u) => $u->where('is_attendance_applicable', true))
                    ->with(['user:id,name,email,employee_code', 'actionedBy:id,name']),
                $actor,
                'attendance.manage'
            );
        } else {
            if (! $actor->is_attendance_applicable) {
                return $this->paginated(new \Illuminate\Pagination\LengthAwarePaginator([], 0, $this->perPage($request)), []);
            }
            $query = AttendanceRegularization::where('user_id', $actor->id)
                ->with(['user:id,name,email,employee_code', 'actionedBy:id,name']);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $paginator = $query->orderByDesc('id')->paginate($this->perPage($request));
        $items = $paginator->getCollection()->map(fn ($r) => $this->formatRegularization($r))->values()->all();

        return $this->paginated($paginator, $items);
    }

    /** POST /attendance/regularizations */
    public function store(RegularizeRequest $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor->is_attendance_applicable) {
            return $this->error('Attendance regularization is not applicable for your account', 403);
        }

        $data = $request->validated();

        $existingAttendance = Attendance::where('user_id', $actor->id)->where('date', $data['date'])->first();

        $regularization = AttendanceRegularization::create([
            'attendance_id'       => $existingAttendance?->id,
            'user_id'             => $actor->id,
            'company_id'          => $actor->company_id,
            'date'                => $data['date'],
            'requested_clock_in'  => $data['requested_clock_in'],
            'requested_clock_out' => $data['requested_clock_out'],
            'reason'              => $data['reason'],
            'status'              => 'pending',
        ]);

        return $this->success($this->formatRegularization($regularization->load('user')), 'Regularization request submitted', 201);
    }

    /** PUT /attendance/regularizations/{id}/approve */
    public function approve(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $reg = AttendanceRegularization::with('user')->find($id);

        if (! $reg || (int) $reg->company_id !== (int) $actor->company_id) {
            return $this->error('Regularization request not found', 404);
        }

        if (! $this->access->canAccessUser($actor, $reg->user, 'attendance.manage')) {
            return $this->error('You do not have permission to approve this regularization', 403);
        }

        if ($reg->status !== 'pending') {
            return $this->error("Cannot approve request with status: {$reg->status}", 422);
        }

        $in  = Carbon::parse($reg->requested_clock_in);
        $out = Carbon::parse($reg->requested_clock_out);
        $totalMinutes = (int) $in->diffInMinutes($out);

        // Update or create Attendance
        $attendance = Attendance::updateOrCreate(
            ['user_id' => $reg->user_id, 'date' => $reg->date->toDateString()],
            [
                'company_id'             => $reg->company_id,
                'clock_in_at'            => $in,
                'clock_out_at'           => $out,
                'clock_in_location_name' => 'Regularized',
                'is_flagged'             => false,
                'flagged_reason'         => null,
                'geofence_status'        => 'inside',
                'status'                 => 'present',
                'total_minutes'          => $totalMinutes,
                'notes'                  => 'Regularized by ' . $actor->name,
            ]
        );

        $reg->update([
            'attendance_id'  => $attendance->id,
            'status'         => 'approved',
            'actioned_by_id' => $actor->id,
        ]);

        return $this->success($this->formatRegularization($reg->fresh(['user', 'actionedBy'])), 'Regularization approved');
    }

    /** PUT /attendance/regularizations/{id}/reject */
    public function reject(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $reg = AttendanceRegularization::with('user')->find($id);

        if (! $reg || (int) $reg->company_id !== (int) $actor->company_id) {
            return $this->error('Regularization request not found', 404);
        }

        if (! $this->access->canAccessUser($actor, $reg->user, 'attendance.manage')) {
            return $this->error('You do not have permission to reject this regularization', 403);
        }

        if ($reg->status !== 'pending') {
            return $this->error("Cannot reject request with status: {$reg->status}", 422);
        }

        $reg->update([
            'status'           => 'rejected',
            'actioned_by_id'   => $actor->id,
            'rejection_reason' => $request->input('rejection_reason', 'Rejected by manager'),
        ]);

        return $this->success($this->formatRegularization($reg->fresh(['user', 'actionedBy'])), 'Regularization rejected');
    }

    private function formatRegularization(AttendanceRegularization $r): array
    {
        return [
            'id'                  => $r->id,
            'attendance_id'       => $r->attendance_id,
            'user_id'             => $r->user_id,
            'user_name'           => $r->user?->name,
            'employee_code'       => $r->user?->employee_code,
            'date'                => $r->date->toDateString(),
            'requested_clock_in'  => $r->requested_clock_in->toIso8601String(),
            'requested_clock_out' => $r->requested_clock_out->toIso8601String(),
            'reason'              => $r->reason,
            'status'              => $r->status,
            'rejection_reason'    => $r->rejection_reason,
            'actioned_by'         => $r->actionedBy ? ['id' => $r->actionedBy->id, 'name' => $r->actionedBy->name] : null,
            'created_at'          => $r->created_at->toIso8601String(),
        ];
    }
}
