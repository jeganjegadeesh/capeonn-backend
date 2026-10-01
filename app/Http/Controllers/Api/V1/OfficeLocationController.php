<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\OfficeLocationRequest;
use App\Models\OfficeLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfficeLocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $locations = OfficeLocation::where('company_id', $request->user()->company_id)
            ->where('is_active', true)
            ->get();

        return $this->success($locations);
    }

    public function store(OfficeLocationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['company_id'] = $request->user()->company_id;

        $location = OfficeLocation::create($data);

        return $this->success($location, 'Office location created', 201);
    }

    public function update(OfficeLocationRequest $request, int $id): JsonResponse
    {
        $location = OfficeLocation::where('company_id', $request->user()->company_id)->find($id);

        if (! $location) {
            return $this->error('Office location not found', 404);
        }

        $location->update($request->validated());

        return $this->success($location, 'Office location updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $location = OfficeLocation::where('company_id', $request->user()->company_id)->find($id);

        if (! $location) {
            return $this->error('Office location not found', 404);
        }

        $location->delete();

        return $this->success(null, 'Office location deleted');
    }
}
