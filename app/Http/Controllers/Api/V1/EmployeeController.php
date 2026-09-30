<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\EmployeeRequest;
use App\Http\Resources\UserResource;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeController extends Controller
{
    private const WITH = [
        'department:id,name',
        'designation:id,name',
        'role:id,slug,name,level',
        'reportsTo:id,name',
    ];

    public function __construct(private AccessControl $access)
    {
    }

    /** GET /employees?search=&department_id=&role=&reports_to_id=&is_active=&per_page=&page= */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $paginator = $this->access
            ->constrainUsers(User::query(), $request->user(), 'employees.view')
            ->with(self::WITH)
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%")
                    ->orWhere('users.employee_code', 'like', "%{$search}%");
            }))
            ->when($request->filled('department_id'), fn ($q) => $q->where('users.department_id', (int) $request->query('department_id')))
            ->when($request->filled('reports_to_id'), fn ($q) => $q->where('users.reports_to_id', (int) $request->query('reports_to_id')))
            ->when($request->filled('role'), fn ($q) => $q->whereHas('role', fn ($r) => $r->where('slug', (string) $request->query('role'))))
            ->when($request->filled('is_active'), fn ($q) => $q->where('users.is_active', $request->boolean('is_active')))
            ->orderBy('users.name')
            ->paginate($this->perPage($request));

        $items = $paginator->getCollection()
            ->map(fn ($user) => (new UserResource($user))->resolve())
            ->values()->all();

        return $this->paginated($paginator, $items);
    }

    public function show(Request $request, int $employee): JsonResponse
    {
        // Outside the caller's scope (or company) looks exactly like "does not exist".
        $user = $this->access
            ->constrainUsers(User::query(), $request->user(), 'employees.view')
            ->with(self::WITH)
            ->findOrFail($employee);

        return $this->success((new UserResource($user))->resolve());
    }

    public function store(EmployeeRequest $request): JsonResponse
    {
        $company = Company::findOrFail($this->companyId($request));

        $data = $request->validated();
        $data['company_id']    = $company->id;
        $data['employee_code'] = $data['employee_code'] ?? $this->nextEmployeeCode($company);
        $data['joined_on']     = $data['joined_on'] ?? now()->toDateString();

        $user = User::create($data)->load(self::WITH);

        return $this->success((new UserResource($user))->resolve(), 'Employee created', 201);
    }

    public function update(EmployeeRequest $request, int $employee): JsonResponse
    {
        $target = $request->target();
        $target->update($request->validated());

        // Deactivated? Sign them out everywhere right away.
        if ($target->wasChanged('is_active') && ! $target->is_active) {
            $target->tokens()->delete();
        }

        return $this->success((new UserResource($target->load(self::WITH)))->resolve(), 'Employee updated');
    }

    public function destroy(Request $request, int $employee): JsonResponse
    {
        $actor  = $request->user();
        $target = User::where('company_id', $this->companyId($request))->with('role')->findOrFail($employee);

        if (! $this->access->canManageUser($actor, $target)) {
            abort(403, 'You do not have permission to perform this action.');
        }

        if ($target->id === $actor->id) {
            return $this->error('You cannot delete your own account.', 422);
        }

        if (User::where('reports_to_id', $target->id)->exists()) {
            return $this->error('This person still has direct reports. Reassign them first.', 409);
        }

        DB::transaction(function () use ($target) {
            Department::where('head_user_id', $target->id)->update(['head_user_id' => null]);
            $target->tokens()->delete();
            $target->delete(); // soft delete: records they created or worked on stay intact
        });

        return $this->success(null, 'Employee deleted');
    }

    /** CAP-0001, CAP-0002 ... skipping any code already used, including by deleted users. */
    private function nextEmployeeCode(Company $company): string
    {
        $number = User::withTrashed()->where('company_id', $company->id)->count() + 1;

        do {
            $code = sprintf('%s-%04d', $company->code, $number++);
        } while (User::withTrashed()->where('company_id', $company->id)->where('employee_code', $code)->exists());

        return $code;
    }
}
