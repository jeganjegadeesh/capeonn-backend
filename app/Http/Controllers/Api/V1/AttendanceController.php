<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ClockInRequest;
use App\Http\Requests\Attendance\ClockOutRequest;
use App\Models\Attendance;
use App\Models\OfficeLocation;
use App\Models\User;
use App\Services\AccessControl;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(private AccessControl $access)
    {
    }

    /** GET /attendance/today */
    public function today(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = now()->toDateString();

        if (! $user->is_attendance_applicable) {
            return $this->success([
                'date'             => $today,
                'attendance'       => null,
                'is_clocked_in'    => false,
                'is_clocked_out'   => false,
                'is_applicable'    => false,
                'elapsed_seconds'  => 0,
                'office_locations' => [],
            ]);
        }

        $attendance = Attendance::where('user_id', $user->id)
            ->whereDate('date', $today)
            ->with('regularization')
            ->first();

        $locations = OfficeLocation::where('company_id', $user->company_id)
            ->where('is_active', true)
            ->get();

        $elapsedSeconds = 0;
        if ($attendance && $attendance->clock_in_at && ! $attendance->clock_out_at) {
            $elapsedSeconds = now()->diffInSeconds($attendance->clock_in_at);
        }

        return $this->success([
            'date'             => $today,
            'attendance'       => $attendance ? $this->formatAttendance($attendance) : null,
            'is_clocked_in'    => $attendance !== null && $attendance->clock_out_at === null,
            'is_clocked_out'   => $attendance !== null && $attendance->clock_out_at !== null,
            'is_applicable'    => true,
            'elapsed_seconds'  => $elapsedSeconds,
            'office_locations' => $locations,
        ]);
    }

    /** POST /attendance/clock-in */
    public function clockIn(ClockInRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->is_attendance_applicable) {
            return $this->error('Attendance check-in is not applicable for your account', 403);
        }

        $today = now()->toDateString();

        $existing = Attendance::where('user_id', $user->id)->whereDate('date', $today)->first();
        if ($existing) {
            return $this->error('You have already clocked in for today', 422);
        }

        $data = $request->validated();
        $lat = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $lon = isset($data['longitude']) ? (float) $data['longitude'] : null;

        $isFlagged = false;
        $flaggedReason = null;
        $geofenceStatus = 'unknown';

        if ($lat !== null && $lon !== null) {
            $locations = OfficeLocation::where('company_id', $user->company_id)
                ->where('is_active', true)
                ->get();

            if ($locations->isNotEmpty()) {
                $withinAny = false;
                $closestDistance = PHP_INT_MAX;
                $closestName = '';

                foreach ($locations as $loc) {
                    $dist = $loc->distanceTo($lat, $lon);
                    if ($dist < $closestDistance) {
                        $closestDistance = $dist;
                        $closestName = $loc->name;
                    }
                    if ($loc->containsCoordinate($lat, $lon)) {
                        $withinAny = true;
                        break;
                    }
                }

                if ($withinAny) {
                    $geofenceStatus = 'inside';
                } else {
                    $geofenceStatus = 'outside';
                    $isFlagged = true;
                    $flaggedReason = sprintf('Clocked in outside geofence (%dm from %s)', (int) $closestDistance, $closestName);
                }
            }
        }

        // Standard work start 09:30 AM
        $now = now();
        $expectedStart = $now->copy()->setTime(9, 30, 0);
        $status = $now->greaterThan($expectedStart) ? 'late' : 'present';

        $attendance = Attendance::create([
            'user_id'                 => $user->id,
            'company_id'              => $user->company_id,
            'date'                    => $today,
            'clock_in_at'             => $now,
            'clock_in_ip'             => $request->ip(),
            'clock_in_latitude'       => $lat,
            'clock_in_longitude'      => $lon,
            'clock_in_location_name'  => $data['location_name'] ?? null,
            'is_flagged'              => $isFlagged,
            'flagged_reason'          => $flaggedReason,
            'geofence_status'         => $geofenceStatus,
            'status'                  => $status,
            'notes'                   => $data['notes'] ?? null,
        ]);

        return $this->success($this->formatAttendance($attendance), 'Clocked in successfully', 201);
    }

    /** POST /attendance/clock-out */
    public function clockOut(ClockOutRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->is_attendance_applicable) {
            return $this->error('Attendance check-out is not applicable for your account', 403);
        }

        $today = now()->toDateString();

        $attendance = Attendance::where('user_id', $user->id)->whereDate('date', $today)->first();
        if (! $attendance) {
            return $this->error('No clock-in record found for today', 422);
        }

        if ($attendance->clock_out_at !== null) {
            return $this->error('You have already clocked out for today', 422);
        }

        $data = $request->validated();
        $lat = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $lon = isset($data['longitude']) ? (float) $data['longitude'] : null;

        $now = now();
        $totalMinutes = (int) $attendance->clock_in_at->diffInMinutes($now);

        // Check for half day (less than 4 hours = 240 mins)
        $status = $attendance->status;
        if ($totalMinutes < 240 && $status === 'present') {
            $status = 'half_day';
        }

        $attendance->update([
            'clock_out_at'            => $now,
            'clock_out_ip'            => $request->ip(),
            'clock_out_latitude'      => $lat,
            'clock_out_longitude'     => $lon,
            'clock_out_location_name' => $data['location_name'] ?? null,
            'total_minutes'           => $totalMinutes,
            'status'                  => $status,
            'notes'                   => $data['notes'] ?? $attendance->notes,
        ]);

        return $this->success($this->formatAttendance($attendance->fresh()), 'Clocked out successfully');
    }

    /** GET /attendance/my-records?month=&year=&per_page= */
    public function myRecords(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->is_attendance_applicable) {
            return $this->paginated(new \Illuminate\Pagination\LengthAwarePaginator([], 0, $this->perPage($request)), []);
        }

        $query = Attendance::where('user_id', $user->id);

        if ($request->filled('month') && $request->filled('year')) {
            $query->whereYear('date', (int) $request->query('year'))
                ->whereMonth('date', (int) $request->query('month'));
        } elseif ($request->filled('year')) {
            $query->whereYear('date', (int) $request->query('year'));
        }

        $paginator = $query->orderByDesc('date')->paginate($this->perPage($request));
        $items = $paginator->getCollection()->map(fn ($a) => $this->formatAttendance($a))->values()->all();

        return $this->paginated($paginator, $items);
    }

    /** GET /attendance/records?date=&user_id=&department_id=&is_flagged=&status= (Manager/Admin) */
    public function records(Request $request): JsonResponse
    {
        $actor = $request->user();

        $query = $this->access->constrainByUserId(
            Attendance::query()
                ->whereHas('user', fn ($u) => $u->where('is_attendance_applicable', true))
                ->with(['user:id,name,email,employee_code,department_id', 'user.department:id,name']),
            $actor,
            'attendance.view'
        );

        if ($request->filled('date')) {
            $query->where('attendances.date', $request->query('date'));
        }
        if ($request->filled('user_id')) {
            $query->where('attendances.user_id', (int) $request->query('user_id'));
        }
        if ($request->filled('is_flagged')) {
            $query->where('attendances.is_flagged', $request->boolean('is_flagged'));
        }
        if ($request->filled('status')) {
            $query->where('attendances.status', $request->query('status'));
        }
        if ($request->filled('department_id')) {
            $deptId = (int) $request->query('department_id');
            $query->whereHas('user', fn ($u) => $u->where('department_id', $deptId));
        }

        $paginator = $query->orderByDesc('attendances.date')->paginate($this->perPage($request));
        $items = $paginator->getCollection()->map(fn ($a) => $this->formatAttendance($a))->values()->all();

        return $this->paginated($paginator, $items);
    }

    /** GET /attendance/summary?year=&month= */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $year = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);

        if (! $user->is_attendance_applicable) {
            return $this->success([
                'year'          => $year,
                'month'         => $month,
                'days_present'  => 0,
                'days_late'     => 0,
                'days_half_day' => 0,
                'total_hours'   => 0.0,
                'total_records' => 0,
            ]);
        }

        $records = Attendance::where('user_id', $user->id)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->get();

        $presentCount = $records->where('status', 'present')->count();
        $lateCount    = $records->where('status', 'late')->count();
        $halfDayCount = $records->where('status', 'half_day')->count();
        $totalMinutes = $records->sum('total_minutes');

        return $this->success([
            'year'          => $year,
            'month'         => $month,
            'days_present'  => $presentCount,
            'days_late'     => $lateCount,
            'days_half_day' => $halfDayCount,
            'total_hours'   => round($totalMinutes / 60, 1),
            'total_records' => $records->count(),
        ]);
    }

    private function formatAttendance(Attendance $a): array
    {
        return [
            'id'                     => $a->id,
            'user_id'                => $a->user_id,
            'user_name'              => $a->user?->name,
            'employee_code'          => $a->user?->employee_code,
            'department_name'        => $a->user?->department?->name,
            'date'                   => $a->date->toDateString(),
            'clock_in_at'            => $a->clock_in_at?->toIso8601String(),
            'clock_out_at'           => $a->clock_out_at?->toIso8601String(),
            'clock_in_ip'            => $a->clock_in_ip,
            'clock_out_ip'           => $a->clock_out_ip,
            'clock_in_location_name' => $a->clock_in_location_name,
            'is_flagged'             => (bool) $a->is_flagged,
            'flagged_reason'         => $a->flagged_reason,
            'geofence_status'        => $a->geofence_status,
            'status'                 => $a->status,
            'total_minutes'          => (int) $a->total_minutes,
            'total_hours_formatted'  => sprintf('%dh %02dm', intdiv($a->total_minutes, 60), $a->total_minutes % 60),
            'notes'                  => $a->notes,
        ];
    }
}
