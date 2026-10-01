<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendanceRegularization;
use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class Phase3CleanupCommand extends Command
{
    protected $signature = 'capeonn:phase3-cleanup';
    protected $description = 'Clean up data for Phase 3 role separation (Super Admin / HR)';

    public function handle(): int
    {
        $this->info('Starting Phase 3 Data Cleanup...');

        $superAdminRole = Role::where('slug', Role::SUPER_ADMIN)->firstOrFail();
        $hrRole         = Role::where('slug', Role::HR)->firstOrFail();

        // 1. Convert existing admin account to Super Admin and set is_attendance_applicable = false
        $adminUsers = User::whereHas('role', fn ($q) => $q->whereIn('slug', ['admin', 'super_admin']))
            ->orWhere('email', config('capeonn.admin.email', 'admin@capeonn.test'))
            ->get();

        foreach ($adminUsers as $admin) {
            $admin->update([
                'role_id'                  => $superAdminRole->id,
                'is_attendance_applicable' => false,
            ]);
            $this->info("Updated {$admin->name} ({$admin->email}) to Super Admin (is_attendance_applicable=false).");
        }

        $superAdminIds = $adminUsers->pluck('id')->all();

        // 2. Remove Super Admin accounts from existing attendance records
        $deletedAttendances = Attendance::whereIn('user_id', $superAdminIds)->delete();
        $deletedRegs = AttendanceRegularization::whereIn('user_id', $superAdminIds)->delete();
        $this->info("Deleted {$deletedAttendances} attendance records and {$deletedRegs} regularization records for Super Admin accounts.");

        // 3. Remove Super Admin accounts from existing leave balances
        $deletedBalances = LeaveBalance::whereIn('user_id', $superAdminIds)->delete();
        $this->info("Deleted {$deletedBalances} leave balances for Super Admin accounts.");

        // 4. Remove Super Admin accounts from existing pending leave requests
        $deletedRequests = LeaveRequest::whereIn('user_id', $superAdminIds)->where('status', 'pending')->delete();
        $this->info("Deleted {$deletedRequests} pending leave requests for Super Admin accounts.");

        // 5. Ensure HR employee account exists
        $company = Company::first();
        if ($company) {
            $hrUser = User::where('company_id', $company->id)->where('role_id', $hrRole->id)->first();
            if (! $hrUser) {
                $hrDept = Department::firstOrCreate(
                    ['company_id' => $company->id, 'code' => 'HR'],
                    ['name' => 'Human Resources']
                );

                $hrDesig = Designation::firstOrCreate(
                    ['company_id' => $company->id, 'name' => 'HR Specialist']
                );

                $hrUser = User::firstOrCreate(
                    ['email' => 'hr@capeonn.test'],
                    [
                        'name'                     => 'Hannah HR',
                        'password'                 => 'Password@123',
                        'company_id'               => $company->id,
                        'department_id'            => $hrDept->id,
                        'designation_id'           => $hrDesig->id,
                        'role_id'                  => $hrRole->id,
                        'employee_code'            => $company->code . '-0008',
                        'joined_on'                => now()->toDateString(),
                        'is_active'                => true,
                        'is_attendance_applicable' => true,
                    ]
                );
                $hrDept->update(['head_user_id' => $hrUser->id]);
                $this->info("Created HR account: {$hrUser->name} ({$hrUser->email})");
            } else {
                $this->info("HR account already exists: {$hrUser->name} ({$hrUser->email})");
            }
        }

        // 6. Reassign any pending leave requests that point to the wrong approver
        $pendingLeaves = LeaveRequest::where('status', 'pending')->with(['user.role', 'user.reportsTo'])->get();
        $reassignedCount = 0;

        foreach ($pendingLeaves as $leave) {
            $applicant = $leave->user;
            if (! $applicant) {
                continue;
            }

            $approverInfo = $this->resolveApprover($applicant);
            if ($leave->approver_id !== $approverInfo['approver_id'] || $leave->final_approver !== $approverInfo['final_approver']) {
                $leave->update([
                    'approver_id'    => $approverInfo['approver_id'],
                    'final_approver' => $approverInfo['final_approver'],
                ]);
                $reassignedCount++;
            }
        }
        $this->info("Reassigned {$reassignedCount} pending leave requests to appropriate approvers.");

        // 7. Initialize leave balances for eligible non-Super Admin employees for 2026
        if ($company) {
            $leaveTypes = LeaveType::where('company_id', $company->id)->where('is_active', true)->get();
            $eligibleUsers = User::where('company_id', $company->id)
                ->where('role_id', '!=', $superAdminRole->id)
                ->get();

            $createdBalancesCount = 0;
            foreach ($eligibleUsers as $u) {
                foreach ($leaveTypes as $lt) {
                    $b = LeaveBalance::firstOrCreate(
                        ['user_id' => $u->id, 'leave_type_id' => $lt->id, 'year' => 2026],
                        [
                            'company_id'   => $company->id,
                            'total_days'   => $lt->annual_days,
                            'used_days'    => 0,
                            'pending_days' => 0,
                        ]
                    );
                    if ($b->wasRecentlyCreated) {
                        $createdBalancesCount++;
                    }
                }
            }
            $this->info("Created {$createdBalancesCount} missing leave balances for eligible employees.");
        }

        $this->info('Phase 3 Data Cleanup completed successfully!');
        return self::SUCCESS;
    }

    private function resolveApprover(User $applicant): array
    {
        $roleSlug = $applicant->role?->slug;

        // 1. Employee -> Team Lead
        if ($roleSlug === Role::EMPLOYEE) {
            if ($applicant->reportsTo && $applicant->reportsTo->role?->slug === Role::TEAM_LEAD) {
                return ['approver_id' => $applicant->reports_to_id, 'final_approver' => false];
            }
            $tl = User::where('company_id', $applicant->company_id)
                ->where('department_id', $applicant->department_id)
                ->whereHas('role', fn ($q) => $q->where('slug', Role::TEAM_LEAD))
                ->where('is_active', true)
                ->first();
            if ($tl) {
                return ['approver_id' => $tl->id, 'final_approver' => false];
            }
            if ($applicant->reports_to_id) {
                return ['approver_id' => $applicant->reports_to_id, 'final_approver' => false];
            }
        }

        // 2. Team Lead -> Manager
        if ($roleSlug === Role::TEAM_LEAD) {
            if ($applicant->reportsTo && $applicant->reportsTo->role?->slug === Role::MANAGER) {
                return ['approver_id' => $applicant->reports_to_id, 'final_approver' => false];
            }
            $mgr = User::where('company_id', $applicant->company_id)
                ->where('department_id', $applicant->department_id)
                ->whereHas('role', fn ($q) => $q->where('slug', Role::MANAGER))
                ->where('is_active', true)
                ->first();
            if ($mgr) {
                return ['approver_id' => $mgr->id, 'final_approver' => false];
            }
            if ($applicant->reports_to_id) {
                return ['approver_id' => $applicant->reports_to_id, 'final_approver' => false];
            }
        }

        // 3. Manager -> HR
        if ($roleSlug === Role::MANAGER) {
            $hr = User::where('company_id', $applicant->company_id)
                ->whereHas('role', fn ($q) => $q->where('slug', Role::HR))
                ->where('is_active', true)
                ->first();
            if ($hr) {
                return ['approver_id' => $hr->id, 'final_approver' => false];
            }
        }

        // 4. HR -> Director / senior Manager (final approver)
        if ($roleSlug === Role::HR) {
            $senior = User::where('company_id', $applicant->company_id)
                ->where('id', '!=', $applicant->id)
                ->whereHas('role', fn ($q) => $q->whereIn('slug', [Role::MANAGER, Role::SUPER_ADMIN, 'admin']))
                ->where('is_active', true)
                ->first();
            if ($senior) {
                return ['approver_id' => $senior->id, 'final_approver' => true];
            }
        }

        $fallback = $applicant->reports_to_id
            ?? User::where('company_id', $applicant->company_id)
                ->where('id', '!=', $applicant->id)
                ->whereHas('role', fn ($q) => $q->whereIn('slug', [Role::HR, Role::MANAGER, Role::SUPER_ADMIN, 'admin']))
                ->value('id');

        return ['approver_id' => $fallback, 'final_approver' => false];
    }
}
