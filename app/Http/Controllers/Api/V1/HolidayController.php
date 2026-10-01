<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Holidays\HolidayRequest;
use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HolidayController extends Controller
{
    /** GET /holidays?year=&upcoming= */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $query = Holiday::where('company_id', $actor->company_id);

        if ($request->filled('year')) {
            $query->whereYear('date', (int) $request->query('year'));
        }

        if ($request->boolean('upcoming')) {
            $query->where('date', '>=', now()->toDateString());
        }

        $holidays = $query->orderBy('date')->get()->map(fn ($h) => [
            'id'           => $h->id,
            'name'         => $h->name,
            'date'         => $h->date->toDateString(),
            'day_of_week'  => $h->date->format('l'),
            'holiday_type' => $h->holiday_type,
            'description'  => $h->description,
            'is_optional'  => (bool) $h->is_optional,
            'is_past'      => $h->date->isPast() && ! $h->date->isToday(),
        ]);

        return $this->success($holidays);
    }

    /** POST /holidays (Super Admin & HR only) */
    public function store(HolidayRequest $request): JsonResponse
    {
        $this->authorizeManage($request);

        $data = $request->validated();
        $data['company_id'] = $request->user()->company_id;

        $holiday = Holiday::create($data);

        return $this->success([
            'id'           => $holiday->id,
            'name'         => $holiday->name,
            'date'         => $holiday->date->toDateString(),
            'day_of_week'  => $holiday->date->format('l'),
            'holiday_type' => $holiday->holiday_type,
            'description'  => $holiday->description,
            'is_optional'  => (bool) $holiday->is_optional,
        ], 'Holiday created successfully', 201);
    }

    /** PUT /holidays/{id} (Super Admin & HR only) */
    public function update(HolidayRequest $request, int $id): JsonResponse
    {
        $this->authorizeManage($request);

        $holiday = Holiday::where('company_id', $request->user()->company_id)->find($id);

        if (! $holiday) {
            return $this->error('Holiday not found', 404);
        }

        $holiday->update($request->validated());

        return $this->success([
            'id'           => $holiday->id,
            'name'         => $holiday->name,
            'date'         => $holiday->date->toDateString(),
            'day_of_week'  => $holiday->date->format('l'),
            'holiday_type' => $holiday->holiday_type,
            'description'  => $holiday->description,
            'is_optional'  => (bool) $holiday->is_optional,
        ], 'Holiday updated successfully');
    }

    /** DELETE /holidays/{id} (Super Admin & HR only) */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeManage($request);

        $holiday = Holiday::where('company_id', $request->user()->company_id)->find($id);

        if (! $holiday) {
            return $this->error('Holiday not found', 404);
        }

        $holiday->delete();

        return $this->success(null, 'Holiday deleted successfully');
    }

    private function authorizeManage(Request $request): void
    {
        $actor = $request->user();
        if (! $actor->isSuperAdmin() && ! $actor->isHR()) {
            abort(403, 'Only Super Admin and HR can manage holidays.');
        }
    }
}
