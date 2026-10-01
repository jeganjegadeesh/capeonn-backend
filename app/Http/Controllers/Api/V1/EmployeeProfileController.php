<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeProfileController extends Controller
{
    public function __construct(private AccessControl $access)
    {
    }

    public function show(Request $request, int $employee): JsonResponse
    {
        $actor = $request->user();
        $user = $this->access->constrainUsers(User::query(), $actor, 'employees.view')
            ->with(['department', 'designation', 'role', 'reportsTo', 'company'])
            ->find($employee);

        if (! $user) {
            return $this->error('Employee not found', 404);
        }

        return $this->success((new UserResource($user))->resolve());
    }

    public function update(UpdateProfileRequest $request, int $employee): JsonResponse
    {
        $actor = $request->user();
        $target = User::where('company_id', $actor->company_id)->find($employee);

        if (! $target) {
            return $this->error('Employee not found', 404);
        }

        $isSelf = (int) $actor->id === (int) $target->id;
        $isHR = $actor->isHR();
        $isSuperAdmin = $actor->isSuperAdmin();
        $canManage = $isHR || $isSuperAdmin || $this->access->canManageUser($actor, $target);

        if (! $isSelf && ! $canManage) {
            return $this->error('You do not have permission to update this profile', 403);
        }

        $data = $request->validated();

        // Salary, joining date and designation editable by HR/Super Admin only
        if (! $isHR && ! $isSuperAdmin) {
            unset($data['salary'], $data['joined_on'], $data['designation_id']);
        }

        // Non-managers cannot edit employment_type or probation_end_date on themselves
        if (! $canManage) {
            unset($data['employment_type'], $data['probation_end_date']);
        }

        $oldDesignationId = $target->designation_id;
        $oldSalary = $target->salary;

        $target->update($data);

        // System-written history logging
        if (isset($data['designation_id']) && $oldDesignationId !== $target->designation_id && $target->designation) {
            \App\Models\EmployeeHistory::create([
                'user_id'         => $target->id,
                'company_id'      => $actor->company_id,
                'event_type'      => 'designation_change',
                'title'           => 'Designation changed to ' . $target->designation->name,
                'description'     => 'Updated by ' . $actor->name,
                'effective_date'  => now()->toDateString(),
                'performed_by_id' => $actor->id,
            ]);
        }

        if (isset($data['salary']) && (float) $oldSalary !== (float) $target->salary) {
            \App\Models\EmployeeHistory::create([
                'user_id'         => $target->id,
                'company_id'      => $actor->company_id,
                'event_type'      => 'salary_revision',
                'title'           => 'Salary revised to ' . number_format((float) $target->salary, 2),
                'description'     => 'Revised by ' . $actor->name,
                'effective_date'  => now()->toDateString(),
                'performed_by_id' => $actor->id,
            ]);
        }

        return $this->success(
            (new UserResource($target->fresh(['department', 'designation', 'role', 'reportsTo', 'company'])))->resolve(),
            'Profile updated successfully'
        );
    }
}
