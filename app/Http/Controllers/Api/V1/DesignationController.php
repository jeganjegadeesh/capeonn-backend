<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\DesignationRequest;
use App\Http\Resources\DesignationResource;
use App\Models\Designation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DesignationController extends Controller
{
    /** GET /designations?search=&is_active=&per_page=&page= */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $paginator = Designation::where('company_id', $this->companyId($request))
            ->withCount(['users as employees_count'])
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        $items = $paginator->getCollection()
            ->map(fn ($designation) => (new DesignationResource($designation))->resolve())
            ->values()->all();

        return $this->paginated($paginator, $items);
    }

    public function show(Request $request, int $designation): JsonResponse
    {
        return $this->success((new DesignationResource($this->find($request, $designation)))->resolve());
    }

    public function store(DesignationRequest $request): JsonResponse
    {
        $designation = Designation::create($request->validated() + ['company_id' => $this->companyId($request)]);

        return $this->success(
            (new DesignationResource($this->find($request, $designation->id)))->resolve(),
            'Designation created',
            201,
        );
    }

    public function update(DesignationRequest $request, int $designation): JsonResponse
    {
        $record = $this->find($request, $designation);
        $record->update($request->validated());

        return $this->success(
            (new DesignationResource($this->find($request, $record->id)))->resolve(),
            'Designation updated',
        );
    }

    public function destroy(Request $request, int $designation): JsonResponse
    {
        $record = $this->find($request, $designation);

        if ($record->users()->exists()) {
            return $this->error('Employees still hold this designation. Reassign them first.', 409);
        }

        $record->delete();

        return $this->success(null, 'Designation deleted');
    }

    private function find(Request $request, int $id): Designation
    {
        return Designation::where('company_id', $this->companyId($request))
            ->withCount(['users as employees_count'])
            ->findOrFail($id);
    }
}
