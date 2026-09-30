<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function __construct(private AccessControl $access)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $roles = $this->access->assignableRoles($request->user());

        return $this->success(
            $roles->map(fn (Role $role) => [
                'id'          => $role->id,
                'name'        => $role->name,
                'slug'        => $role->slug,
                'level'       => $role->level,
                'description' => $role->description,
            ])->values()->all()
        );
    }
}
