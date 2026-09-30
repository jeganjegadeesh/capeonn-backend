<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HierarchyController extends Controller
{
    public function __construct(private AccessControl $access)
    {
    }

    /**
     * GET /hierarchy?include_inactive=1
     * Admin (scope "all"): the whole company as a list of top-level trees.
     * Everyone else: one tree, starting with themselves and going down through their reports.
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        $users = User::where('company_id', $this->companyId($request))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->with(['role:id,slug,name,level', 'designation:id,name'])
            ->get(['id', 'name', 'employee_code', 'is_active', 'reports_to_id', 'role_id', 'designation_id'])
            ->sortBy('name');

        if ($actor->scopeFor('employees.view') === Permission::SCOPE_ALL) {
            $ids   = $users->pluck('id')->flip();
            $roots = $users->filter(fn ($u) => $u->reports_to_id === null || ! $ids->has($u->reports_to_id));
        } else {
            $visible = array_merge([$actor->id], $this->access->subordinateIds($actor));
            $users   = $users->whereIn('id', $visible);
            $roots   = $users->where('id', $actor->id);
        }

        $byParent = $users->whereNotNull('reports_to_id')->groupBy('reports_to_id');
        $visited  = [];

        $build = function (User $user) use (&$build, &$visited, $byParent): array {
            $visited[$user->id] = true;

            $children = ($byParent->get($user->id) ?? collect())
                ->reject(fn ($child) => isset($visited[$child->id]))
                ->values();

            return [
                'id'            => $user->id,
                'name'          => $user->name,
                'employee_code' => $user->employee_code,
                'is_active'     => $user->is_active,
                'role'          => $user->role?->slug,
                'designation'   => $user->designation?->name,
                'reports'       => $children->map(fn ($child) => $build($child))->all(),
            ];
        };

        return $this->success($roots->values()->map(fn ($root) => $build($root))->all());
    }
}
