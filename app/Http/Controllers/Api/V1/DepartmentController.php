<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\DepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    /** GET /departments?search=&is_active=&per_page=&page= */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $paginator = Department::where('company_id', $this->companyId($request))
            ->with('head:id,name')
            ->withCount(['users as employees_count'])
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
            }))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        $items = $paginator->getCollection()
            ->map(fn ($department) => (new DepartmentResource($department))->resolve())
            ->values()->all();

        return $this->paginated($paginator, $items);
    }

    public function show(Request $request, int $department): JsonResponse
    {
        return $this->success((new DepartmentResource($this->find($request, $department)))->resolve());
    }

    public function store(DepartmentRequest $request): JsonResponse
    {
        $department = Department::create($request->validated() + ['company_id' => $this->companyId($request)]);

        return $this->success(
            (new DepartmentResource($this->find($request, $department->id)))->resolve(),
            'Department created',
            201,
        );
    }

    public function update(DepartmentRequest $request, int $department): JsonResponse
    {
        $record = $this->find($request, $department);
        $record->update($request->validated());

        return $this->success(
            (new DepartmentResource($this->find($request, $record->id)))->resolve(),
            'Department updated',
        );
    }

    public function destroy(Request $request, int $department): JsonResponse
    {
        $record = $this->find($request, $department);

        if ($record->users()->exists()) {
            return $this->error('This department still has employees. Move or deactivate them first.', 409);
        }

        $record->delete();

        return $this->success(null, 'Department deleted');
    }

    /** Looks the record up inside the user's company, so other companies' ids give a 404. */
    private function find(Request $request, int $id): Department
    {
        return Department::where('company_id', $this->companyId($request))
            ->with('head:id,name')
            ->withCount(['users as employees_count'])
            ->findOrFail($id);
    }
}
