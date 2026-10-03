<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\Projects\ProjectAssignedEvent;
use App\Events\Projects\ProjectCompletionRequestedEvent;
use App\Events\Projects\ProjectDeadlineChangedEvent;
use App\Events\Projects\ProjectMemberAddedEvent;
use App\Events\Projects\ProjectMemberRemovedEvent;
use App\Events\Projects\ProjectStatusChangedEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\ProjectAssignLeadRequest;
use App\Http\Requests\Projects\ProjectMemberRequest;
use App\Http\Requests\Projects\ProjectRequest;
use App\Http\Requests\Projects\ProjectStatusRequest;
use App\Http\Resources\ProjectActivityResource;
use App\Http\Resources\ProjectDetailResource;
use App\Http\Resources\ProjectMemberResource;
use App\Http\Resources\ProjectResource;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessControl;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    private const WITH = [
        'department:id,name,code',
        'manager:id,name',
        'teamLead:id,name,email,employee_code',
        'createdBy:id,name',
    ];

    public function __construct(private AccessControl $access)
    {
    }

    /** GET /projects */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');
        $includeArchived = $request->boolean('include_archived') || $status === Project::STATUS_ARCHIVED;

        $query = $this->access->constrainProjects(Project::query(), $actor, 'projects.view')
            ->with(self::WITH)
            ->withCount(['members'])
            ->when(! $includeArchived, fn ($q) => $q->where('projects.status', '!=', Project::STATUS_ARCHIVED))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('projects.name', 'like', "%{$search}%")
                        ->orWhere('projects.code', 'like', "%{$search}%")
                        ->orWhere('projects.client_name', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('department_id'), fn ($q) => $q->where('projects.department_id', (int) $request->query('department_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('projects.status', $request->query('status')))
            ->when($request->filled('priority'), fn ($q) => $q->where('projects.priority', $request->query('priority')))
            ->when($request->filled('team_lead_id'), fn ($q) => $q->where('projects.team_lead_id', (int) $request->query('team_lead_id')))
            ->when($request->boolean('my_projects'), function ($q) use ($actor) {
                $q->where(function ($sub) use ($actor) {
                    $sub->where('projects.team_lead_id', $actor->id)
                        ->orWhere('projects.manager_id', $actor->id)
                        ->orWhereHas('members', fn ($m) => $m->where('users.id', $actor->id));
                });
            })
            ->when($request->boolean('is_overdue'), function ($q) {
                $q->whereNotIn('projects.status', [Project::STATUS_COMPLETED, Project::STATUS_ARCHIVED, Project::STATUS_CANCELLED])
                    ->whereNotNull('projects.deadline')
                    ->where('projects.deadline', '<', Carbon::today()->toDateString());
            })
            ->orderByDesc('projects.id');

        $paginator = $query->paginate($this->perPage($request));

        $items = $paginator->getCollection()
            ->map(fn ($project) => (new ProjectResource($project))->resolve())
            ->values()->all();

        return $this->paginated($paginator, $items);
    }

    /** GET /projects/dashboard */
    public function dashboard(Request $request): JsonResponse
    {
        $actor = $request->user();
        $baseQuery = $this->access->constrainProjects(Project::query(), $actor, 'projects.view');
        $today = Carbon::today()->toDateString();

        // Single aggregate query for all status counts, priority breakdown, and overdue metrics
        $metrics = (clone $baseQuery)
            ->selectRaw("
                COUNT(*) as total_projects,
                COUNT(CASE WHEN status IN ('active', 'in_progress') THEN 1 END) as active_count,
                COUNT(CASE WHEN status IN ('planned', 'planning') THEN 1 END) as planned_count,
                COUNT(CASE WHEN status = 'on_hold' THEN 1 END) as on_hold_count,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count,
                COUNT(CASE WHEN status = 'archived' THEN 1 END) as archived_count,
                COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_count,
                COUNT(CASE WHEN status NOT IN ('completed', 'archived', 'cancelled') AND deadline IS NOT NULL AND deadline < ? THEN 1 END) as overdue_count,
                COUNT(CASE WHEN priority = 'low' THEN 1 END) as priority_low,
                COUNT(CASE WHEN priority = 'medium' THEN 1 END) as priority_medium,
                COUNT(CASE WHEN priority = 'high' THEN 1 END) as priority_high,
                COUNT(CASE WHEN priority = 'urgent' THEN 1 END) as priority_urgent
            ", [$today])
            ->first();

        $upcomingDeadlines = (clone $baseQuery)
            ->whereNotIn('status', [Project::STATUS_COMPLETED, Project::STATUS_ARCHIVED, Project::STATUS_CANCELLED])
            ->whereNotNull('deadline')
            ->where('deadline', '>=', $today)
            ->with(['department:id,name', 'manager:id,name', 'teamLead:id,name'])
            ->orderBy('deadline')
            ->limit(5)
            ->get()
            ->map(fn ($p) => (new ProjectResource($p))->resolve())
            ->all();

        $recentProjects = (clone $baseQuery)
            ->with(['department:id,name', 'manager:id,name', 'teamLead:id,name'])
            ->withCount(['members'])
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get()
            ->map(fn ($p) => (new ProjectResource($p))->resolve())
            ->all();

        return $this->success([
            'total_projects' => (int) ($metrics->total_projects ?? 0),
            'active_count' => (int) ($metrics->active_count ?? 0),
            'planned_count' => (int) ($metrics->planned_count ?? 0),
            'in_progress_count' => (int) ($metrics->active_count ?? 0), // backwards compatibility
            'planning_count' => (int) ($metrics->planned_count ?? 0),   // backwards compatibility
            'on_hold_count' => (int) ($metrics->on_hold_count ?? 0),
            'completed_count' => (int) ($metrics->completed_count ?? 0),
            'archived_count' => (int) ($metrics->archived_count ?? 0),
            'cancelled_count' => (int) ($metrics->cancelled_count ?? 0),
            'overdue_count' => (int) ($metrics->overdue_count ?? 0),
            'priority_breakdown' => [
                'low' => (int) ($metrics->priority_low ?? 0),
                'medium' => (int) ($metrics->priority_medium ?? 0),
                'high' => (int) ($metrics->priority_high ?? 0),
                'urgent' => (int) ($metrics->priority_urgent ?? 0),
            ],
            'upcoming_deadlines' => $upcomingDeadlines,
            'recent_projects' => $recentProjects,
        ]);
    }

    /** GET /projects/{project} */
    public function show(Request $request, int $project): JsonResponse
    {
        $actor = $request->user();
        $record = $this->findProjectForActor($request, $project, 'projects.view');

        $loads = [
            'department:id,name,code',
            'manager:id,name',
            'teamLead:id,name,email,employee_code',
            'createdBy:id,name',
            'members' => fn ($q) => $q->with(['department:id,name', 'designation:id,name', 'role:id,slug,name']),
        ];

        // Only return audit activities if actor is authorized
        if ($this->access->canViewProjectActivity($actor, $record)) {
            $loads['activities'] = fn ($q) => $q->with('user:id,name,email')->limit(25);
        }

        $record->load($loads);

        return $this->success((new ProjectDetailResource($record))->resolve());
    }

    /** POST /projects */
    public function store(ProjectRequest $request): JsonResponse
    {
        $actor = $request->user();
        $companyId = $this->companyId($request);

        $scope = $actor->scopeFor('projects.manage');
        if ($scope === Permission::SCOPE_DEPARTMENT) {
            if ((int) $request->input('department_id') !== (int) $actor->department_id) {
                return $this->error('Managers can only create projects within their own department.', 403);
            }
        }

        $data = $request->validated();
        $membersData = $data['members'] ?? null;
        unset($data['members']);

        $data['company_id'] = $companyId;
        $data['created_by_id'] = $actor->id;
        $data['status'] = $data['status'] ?? Project::STATUS_PLANNED;
        $data['priority'] = $data['priority'] ?? Project::PRIORITY_MEDIUM;
        $data['progress'] = null;

        if (! isset($data['manager_id'])) {
            $data['manager_id'] = $actor->hasRole(Role::MANAGER) ? $actor->id : null;
        }

        $attempts = 0;
        $project = null;

        while ($attempts < 3) {
            try {
                $attempts++;
                if (empty($data['code']) || $attempts > 1) {
                    $data['code'] = $this->nextProjectCode($companyId);
                }

                $project = DB::transaction(function () use ($data, $membersData, $actor) {
                    $proj = Project::create($data);

                    // Audit record: creation
                    $proj->recordActivity(
                        action: 'created',
                        description: "Project created by {$actor->name}",
                        userId: $actor->id,
                        reason: 'Project initialized'
                    );

                    // Lead assigned?
                    if ($proj->team_lead_id) {
                        $lead = User::find($proj->team_lead_id);
                        if ($lead) {
                            $proj->recordActivity(
                                action: 'lead_assigned',
                                description: "{$lead->name} assigned as Team Lead by {$actor->name}",
                                userId: $actor->id,
                                field: 'team_lead_id',
                                newValue: (string) $lead->id,
                                reason: 'Initial Team Lead assignment',
                                metadata: [
                                    'team_lead_id' => $lead->id,
                                    'team_lead_name' => $lead->name,
                                ]
                            );
                            event(new ProjectAssignedEvent($proj, $lead, $actor));
                        }
                    }

                    // Initial members attached?
                    if (! empty($membersData) && is_array($membersData)) {
                        $syncData = [];
                        foreach ($membersData as $m) {
                            $userId = (int) $m['user_id'];
                            $syncData[$userId] = [
                                'project_role' => $m['project_role'] ?? 'Member',
                                'assigned_by_id' => $actor->id,
                                'assigned_at' => now(),
                            ];
                        }
                        $proj->members()->sync($syncData);
                        $proj->recordActivity(
                            action: 'member_added',
                            description: count($syncData) . ' initial team members added by ' . $actor->name,
                            userId: $actor->id
                        );

                        foreach ($syncData as $uid => $meta) {
                            $mUser = User::find($uid);
                            if ($mUser) {
                                event(new ProjectMemberAddedEvent($proj, $mUser, $meta['project_role'] ?? 'Member', $actor));
                            }
                        }
                    }

                    return $proj;
                });
                break;
            } catch (\Illuminate\Database\QueryException $e) {
                if ($attempts >= 3 || (! str_contains($e->getMessage(), 'UNIQUE') && ! str_contains($e->getMessage(), 'unique'))) {
                    throw $e;
                }
            }
        }

        $project->load([
            'department:id,name,code',
            'manager:id,name',
            'teamLead:id,name,email,employee_code',
            'createdBy:id,name',
            'members' => fn ($q) => $q->with(['department:id,name', 'designation:id,name', 'role:id,slug,name']),
            'activities.user:id,name,email',
        ]);

        return $this->success((new ProjectDetailResource($project))->resolve(), 'Project created', 201);
    }

    /** PUT /projects/{project} */
    public function update(ProjectRequest $request, int $project): JsonResponse
    {
        $actor = $request->user();
        $record = $this->findProjectForActor($request, $project, 'projects.manage');

        $scope = $actor->scopeFor('projects.manage');
        if ($scope === Permission::SCOPE_DEPARTMENT) {
            if ($request->has('department_id') && (int) $request->input('department_id') !== (int) $actor->department_id) {
                return $this->error('Managers cannot move projects to another department.', 403);
            }
        }

        $data = $request->validated();
        unset($data['members']); // members are managed via dedicated endpoints
        unset($data['status']);  // status must be changed through /status endpoint
        unset($data['progress']); // task metrics are computed in Phase 5

        $oldPriority = $record->priority;
        $oldDeadline = $record->deadline;
        $oldLeadId = $record->team_lead_id;

        DB::transaction(function () use ($record, $data, $actor, $oldPriority, $oldDeadline, $oldLeadId, $request) {
            $record->fill($data);
            $dirty = $record->getDirty();

            if (empty($dirty)) {
                return; // Nothing changed, don't spam audit trail
            }

            $record->save();

            // Deadline change audit
            if (array_key_exists('deadline', $dirty)) {
                $newDeadline = $record->deadline;
                $record->recordActivity(
                    action: 'deadline_changed',
                    description: 'Deadline changed from ' . ($oldDeadline ? $oldDeadline->toDateString() : 'none') . ' to ' . ($newDeadline ? $newDeadline->toDateString() : 'none') . " by {$actor->name}",
                    userId: $actor->id,
                    field: 'deadline',
                    oldValue: $oldDeadline?->toDateString(),
                    newValue: $newDeadline?->toDateString(),
                );
                event(new ProjectDeadlineChangedEvent($record, $oldDeadline?->toDateString(), $newDeadline?->toDateString(), $actor));
            }

            // Priority change audit
            if (array_key_exists('priority', $dirty)) {
                $record->recordActivity(
                    action: 'priority_changed',
                    description: "Priority changed from " . ucfirst($oldPriority) . " to " . ucfirst($record->priority) . " by {$actor->name}",
                    userId: $actor->id,
                    field: 'priority',
                    oldValue: $oldPriority,
                    newValue: $record->priority,
                );
            }

            // Team Lead change audit
            if (array_key_exists('team_lead_id', $dirty)) {
                $newLeadId = $record->team_lead_id;
                $reason = $request->input('reason');
                $keepAsMember = $request->boolean('keep_as_member', true);

                if ($oldLeadId && $keepAsMember) {
                    if (! $record->members()->where('users.id', $oldLeadId)->exists()) {
                        $record->members()->attach($oldLeadId, [
                            'project_role' => 'Member',
                            'assigned_by_id' => $actor->id,
                            'assigned_at' => now(),
                        ]);
                    }
                }

                if ($newLeadId) {
                    $newLead = User::find($newLeadId);
                    $record->recordActivity(
                        action: 'lead_assigned',
                        description: "{$newLead->name} assigned as Team Lead by {$actor->name}",
                        userId: $actor->id,
                        field: 'team_lead_id',
                        oldValue: (string) $oldLeadId,
                        newValue: (string) $newLeadId,
                        reason: $reason,
                        metadata: ['team_lead_id' => $newLead->id, 'team_lead_name' => $newLead->name]
                    );
                    event(new ProjectAssignedEvent($record, $newLead, $actor));
                } else {
                    $record->recordActivity(
                        action: 'lead_removed',
                        description: "Team Lead unassigned by {$actor->name}",
                        userId: $actor->id,
                        field: 'team_lead_id',
                        oldValue: (string) $oldLeadId,
                        newValue: null,
                        reason: $reason
                    );
                }
            }

            // General update audit if non-specific fields changed
            $otherChanges = array_diff(array_keys($dirty), ['deadline', 'priority', 'team_lead_id', 'updated_at']);
            if (! empty($otherChanges)) {
                $record->recordActivity(
                    action: 'updated',
                    description: "Project details updated by {$actor->name}",
                    userId: $actor->id,
                    reason: 'Project properties edited'
                );
            }
        });

        $record->load([
            'department:id,name,code',
            'manager:id,name',
            'teamLead:id,name,email,employee_code',
            'createdBy:id,name',
            'members' => fn ($q) => $q->with(['department:id,name', 'designation:id,name', 'role:id,slug,name']),
            'activities.user:id,name,email',
        ]);

        return $this->success((new ProjectDetailResource($record))->resolve(), 'Project updated');
    }

    /** POST /projects/{project}/status */
    public function updateStatus(ProjectStatusRequest $request, int $project): JsonResponse
    {
        $actor = $request->user();
        $record = $this->findProjectForActor($request, $project, 'projects.status');

        $oldStatus = $record->status;
        $newStatus = $request->input('status');
        $reason = $request->input('reason');

        if ($oldStatus === $newStatus) {
            return $this->success((new ProjectDetailResource($record))->resolve(), 'Status unchanged');
        }

        // Validate allowed transitions
        $allowed = Project::ALLOWED_TRANSITIONS[$oldStatus] ?? [];
        if (! in_array($newStatus, $allowed, true)) {
            return $this->error("Invalid status transition from '{$oldStatus}' to '{$newStatus}'.", 422);
        }

        // Team Leads cannot mark completed or archived directly (they use request-completion)
        if (in_array($newStatus, [Project::STATUS_COMPLETED, Project::STATUS_ARCHIVED], true) && ! $this->access->canManageProject($actor, $record)) {
            return $this->error('Team Leads cannot transition directly to ' . ucfirst($newStatus) . '. Please use Request Completion instead.', 403);
        }

        DB::transaction(function () use ($record, $oldStatus, $newStatus, $reason, $actor) {
            $record->status = $newStatus;
            $record->status_changed_by_id = $actor->id;
            $record->status_changed_at = now();
            $record->status_change_reason = $reason;

            if ($newStatus === Project::STATUS_COMPLETED) {
                $record->completion_requested_at = null;
                $record->completion_requested_by_id = null;
                $record->completion_request_notes = null;
            }

            $record->save();

            $record->recordActivity(
                action: 'status_changed',
                description: "Status changed from " . ucfirst(str_replace('_', ' ', $oldStatus)) . " to " . ucfirst(str_replace('_', ' ', $newStatus)) . " by {$actor->name}" . ($reason ? ": {$reason}" : ''),
                userId: $actor->id,
                field: 'status',
                oldValue: $oldStatus,
                newValue: $newStatus,
                reason: $reason,
                metadata: ['from' => $oldStatus, 'to' => $newStatus]
            );

            event(new ProjectStatusChangedEvent($record, $oldStatus, $newStatus, $reason, $actor));
        });

        $record->load([
            'department:id,name,code',
            'manager:id,name',
            'teamLead:id,name,email,employee_code',
            'createdBy:id,name',
            'members' => fn ($q) => $q->with(['department:id,name', 'designation:id,name', 'role:id,slug,name']),
            'activities.user:id,name,email',
        ]);

        return $this->success((new ProjectDetailResource($record))->resolve(), 'Project status updated');
    }

    /** POST /projects/{project}/request-completion */
    public function requestCompletion(Request $request, int $project): JsonResponse
    {
        $actor = $request->user();
        $record = $this->findProjectForActor($request, $project, 'projects.view');

        if (! ($record->team_lead_id === $actor->id || $this->access->canManageProject($actor, $record))) {
            return $this->error('Only the assigned Team Lead or a Manager can request project completion.', 403);
        }

        if ($record->status !== Project::STATUS_ACTIVE) {
            return $this->error('Completion can only be requested for active projects.', 422);
        }

        if ($record->completion_requested_at !== null) {
            return $this->error('Completion has already been requested for this project.', 422);
        }

        $notes = $request->input('notes');

        DB::transaction(function () use ($record, $notes, $actor) {
            $record->completion_requested_at = now();
            $record->completion_requested_by_id = $actor->id;
            $record->completion_request_notes = $notes;
            $record->save();

            $record->recordActivity(
                action: 'completion_requested',
                description: "Completion requested by {$actor->name}" . ($notes ? ": {$notes}" : ''),
                userId: $actor->id,
                reason: $notes,
            );

            event(new ProjectCompletionRequestedEvent($record, $actor, $notes));
        });

        $record->load([
            'department:id,name,code',
            'manager:id,name',
            'teamLead:id,name,email,employee_code',
            'createdBy:id,name',
            'members' => fn ($q) => $q->with(['department:id,name', 'designation:id,name', 'role:id,slug,name']),
            'activities.user:id,name,email',
        ]);

        return $this->success((new ProjectDetailResource($record))->resolve(), 'Completion requested');
    }

    /** POST /projects/{project}/approve-completion */
    public function approveCompletion(Request $request, int $project): JsonResponse
    {
        $actor = $request->user();
        $record = $this->findProjectForActor($request, $project, 'projects.manage');

        if (! $this->access->canManageProject($actor, $record)) {
            return $this->error('Only managers or administrators can approve project completion.', 403);
        }

        if ($record->status !== Project::STATUS_ACTIVE) {
            return $this->error('Only active projects can be marked as completed.', 422);
        }

        $oldStatus = $record->status;

        DB::transaction(function () use ($record, $oldStatus, $actor) {
            $record->status = Project::STATUS_COMPLETED;
            $record->status_changed_by_id = $actor->id;
            $record->status_changed_at = now();
            $record->status_change_reason = 'Completion approved';
            $record->completion_requested_at = null;
            $record->completion_requested_by_id = null;
            $record->completion_request_notes = null;
            $record->save();

            $record->recordActivity(
                action: 'completion_approved',
                description: "Completion approved by {$actor->name}",
                userId: $actor->id,
                field: 'status',
                oldValue: $oldStatus,
                newValue: Project::STATUS_COMPLETED,
                reason: 'Completion approved',
            );

            event(new ProjectStatusChangedEvent($record, $oldStatus, Project::STATUS_COMPLETED, 'Completion approved', $actor));
        });

        $record->load([
            'department:id,name,code',
            'manager:id,name',
            'teamLead:id,name,email,employee_code',
            'createdBy:id,name',
            'members' => fn ($q) => $q->with(['department:id,name', 'designation:id,name', 'role:id,slug,name']),
            'activities.user:id,name,email',
        ]);

        return $this->success((new ProjectDetailResource($record))->resolve(), 'Project completion approved');
    }

    /** POST /projects/{project}/reject-completion */
    public function rejectCompletion(Request $request, int $project): JsonResponse
    {
        $actor = $request->user();
        $record = $this->findProjectForActor($request, $project, 'projects.manage');

        if (! $this->access->canManageProject($actor, $record)) {
            return $this->error('Only managers or administrators can reject project completion.', 403);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $reason = $validated['reason'];

        DB::transaction(function () use ($record, $reason, $actor) {
            $record->completion_requested_at = null;
            $record->completion_requested_by_id = null;
            $record->completion_request_notes = null;
            $record->save();

            $record->recordActivity(
                action: 'completion_rejected',
                description: "Completion request rejected by {$actor->name}: {$reason}",
                userId: $actor->id,
                reason: $reason,
            );
        });

        $record->load([
            'department:id,name,code',
            'manager:id,name',
            'teamLead:id,name,email,employee_code',
            'createdBy:id,name',
            'members' => fn ($q) => $q->with(['department:id,name', 'designation:id,name', 'role:id,slug,name']),
            'activities.user:id,name,email',
        ]);

        return $this->success((new ProjectDetailResource($record))->resolve(), 'Project completion rejected');
    }

    /** DELETE /projects/{project} */
    public function destroy(Request $request, int $project): JsonResponse
    {
        $actor = $request->user();
        $record = Project::where('company_id', $this->companyId($request))->findOrFail($project);

        if (! $actor->hasRole(Role::SUPER_ADMIN, Role::ADMIN)) {
            return $this->error('Only administrators can delete projects. Managers may archive instead.', 403);
        }

        if ($record->members()->exists()) {
            return $this->error('Only empty projects with no members can be deleted. Please archive this project instead.', 422);
        }

        DB::transaction(function () use ($record, $actor) {
            $record->recordActivity(
                action: 'deleted',
                description: "Project deleted by {$actor->name}",
                userId: $actor->id,
                reason: 'Administrative deletion'
            );
            $record->delete();
        });

        return $this->success(null, 'Project deleted');
    }

    /** POST /projects/{project}/lead */
    public function assignLead(ProjectAssignLeadRequest $request, int $project): JsonResponse
    {
        $actor = $request->user();
        $record = $this->findProjectForActor($request, $project, 'projects.assign');

        if (! $this->access->canAssignLead($actor, $record)) {
            return $this->error('You do not have permission to assign a Team Lead to this project.', 403);
        }

        $leadId = $request->input('team_lead_id');
        $reason = $request->input('reason');
        $keepAsMember = $request->boolean('keep_as_member', true);
        $oldLeadId = $record->team_lead_id;

        if ((int) $leadId === (int) $oldLeadId) {
            return $this->success((new ProjectDetailResource($record))->resolve(), 'No change in Team Lead');
        }

        DB::transaction(function () use ($record, $leadId, $oldLeadId, $actor, $reason, $keepAsMember) {
            $record->team_lead_id = $leadId;
            $record->save();

            if ($oldLeadId && $keepAsMember) {
                if (! $record->members()->where('users.id', $oldLeadId)->exists()) {
                    $record->members()->attach($oldLeadId, [
                        'project_role' => 'Member',
                        'assigned_by_id' => $actor->id,
                        'assigned_at' => now(),
                    ]);
                    $oldLeadUser = User::find($oldLeadId);
                    $record->recordActivity(
                        action: 'member_added',
                        description: "Previous lead {$oldLeadUser?->name} retained as project member",
                        userId: $actor->id,
                        reason: 'Retained as member upon lead reassignment'
                    );
                }
            }

            if ($leadId) {
                $lead = User::find($leadId);
                $oldLead = $oldLeadId ? User::find($oldLeadId) : null;
                $desc = $oldLead
                    ? "Team Lead changed from {$oldLead->name} to {$lead->name} by {$actor->name}"
                    : "{$lead->name} assigned as Team Lead by {$actor->name}";

                $record->recordActivity(
                    action: 'lead_assigned',
                    description: $desc . ($reason ? ": {$reason}" : ''),
                    userId: $actor->id,
                    field: 'team_lead_id',
                    oldValue: $oldLead ? (string) $oldLead->id : null,
                    newValue: (string) $lead->id,
                    reason: $reason,
                    metadata: ['team_lead_id' => $lead->id, 'team_lead_name' => $lead->name]
                );

                event(new ProjectAssignedEvent($record, $lead, $actor));
            } else {
                $record->recordActivity(
                    action: 'lead_removed',
                    description: "Team Lead unassigned by {$actor->name}" . ($reason ? ": {$reason}" : ''),
                    userId: $actor->id,
                    field: 'team_lead_id',
                    oldValue: (string) $oldLeadId,
                    newValue: null,
                    reason: $reason
                );
            }
        });

        $record->load([
            'department:id,name,code',
            'manager:id,name',
            'teamLead:id,name,email,employee_code',
            'createdBy:id,name',
            'members' => fn ($q) => $q->with(['department:id,name', 'designation:id,name', 'role:id,slug,name']),
            'activities.user:id,name,email',
        ]);

        return $this->success((new ProjectDetailResource($record))->resolve(), 'Team Lead assigned');
    }

    /** GET /projects/{project}/members */
    public function members(Request $request, int $project): JsonResponse
    {
        $record = $this->findProjectForActor($request, $project, 'projects.view');
        $members = $record->members()->with(['department:id,name', 'designation:id,name', 'role:id,slug,name'])->get();

        return $this->success(ProjectMemberResource::collection($members)->resolve());
    }

    /** POST /projects/{project}/members */
    public function addMember(ProjectMemberRequest $request, int $project): JsonResponse
    {
        $actor = $request->user();
        $record = Project::where('company_id', $this->companyId($request))->findOrFail($project);

        if (! $this->access->canManageProjectTeam($actor, $record)) {
            if (in_array(strtolower($record->status), [Project::STATUS_ON_HOLD, Project::STATUS_COMPLETED, Project::STATUS_ARCHIVED, Project::STATUS_CANCELLED], true)) {
                return $this->error("Cannot add members to a project with status '{$record->status}'.", 422);
            }
            return $this->error('You do not have permission to manage members on this project.', 403);
        }

        $userId = (int) $request->input('user_id');
        $projectRole = $request->input('project_role', 'Member') ?: 'Member';

        if ($record->members()->where('users.id', $userId)->exists()) {
            return $this->error('This employee is already a member of this project.', 422);
        }

        $user = User::findOrFail($userId);

        // Check availability & workload warnings
        $warnings = [];

        // 1. Leave overlap warning
        $pStart = $record->start_date ? $record->start_date->toDateString() : Carbon::today()->toDateString();
        $pEnd = $record->deadline ? $record->deadline->toDateString() : Carbon::parse($pStart)->addDays(30)->toDateString();

        $leaveOverlaps = LeaveRequest::where('user_id', $userId)
            ->where('status', 'approved')
            ->where(function ($q) use ($pStart, $pEnd) {
                $q->whereBetween('start_date', [$pStart, $pEnd])
                    ->orWhereBetween('end_date', [$pStart, $pEnd])
                    ->orWhere(function ($sub) use ($pStart, $pEnd) {
                        $sub->where('start_date', '<=', $pStart)
                            ->where('end_date', '>=', $pEnd);
                    });
            })
            ->get();

        foreach ($leaveOverlaps as $leave) {
            $warnings[] = "{$user->name} has approved leave from {$leave->start_date->toDateString()} to {$leave->end_date->toDateString()}.";
        }

        // 2. Active projects workload check (limit >= 3)
        $activeProjectsCount = Project::where('company_id', $record->company_id)
            ->whereNotIn('status', [Project::STATUS_COMPLETED, Project::STATUS_ARCHIVED, Project::STATUS_CANCELLED])
            ->where(function ($q) use ($userId) {
                $q->where('team_lead_id', $userId)
                    ->orWhereHas('members', fn ($m) => $m->where('users.id', $userId));
            })
            ->count();

        if ($activeProjectsCount >= 3) {
            $warnings[] = "{$user->name} is already assigned to {$activeProjectsCount} active projects (exceeds workload threshold of 3 projects).";
        }

        DB::transaction(function () use ($record, $userId, $projectRole, $user, $actor) {
            $record->members()->attach($userId, [
                'project_role' => $projectRole,
                'assigned_by_id' => $actor->id,
                'assigned_at' => now(),
            ]);

            $record->recordActivity(
                action: 'member_added',
                description: "{$user->name} added as {$projectRole} by {$actor->name}",
                userId: $actor->id,
                field: 'members',
                newValue: (string) $user->id,
                metadata: ['user_id' => $user->id, 'user_name' => $user->name, 'project_role' => $projectRole]
            );

            event(new ProjectMemberAddedEvent($record, $user, $projectRole, $actor));
        });

        $record->load([
            'department:id,name,code',
            'manager:id,name',
            'teamLead:id,name,email,employee_code',
            'createdBy:id,name',
            'members' => fn ($q) => $q->with(['department:id,name', 'designation:id,name', 'role:id,slug,name']),
            'activities.user:id,name,email',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Member added to project',
            'data' => (new ProjectDetailResource($record))->resolve(),
            'warnings' => $warnings,
        ], 201);
    }

    /** PUT /projects/{project}/members/{member} */
    public function updateMember(Request $request, int $project, int $member): JsonResponse
    {
        $actor = $request->user();
        $record = Project::where('company_id', $this->companyId($request))->findOrFail($project);

        if (! $this->access->canManageProjectTeam($actor, $record)) {
            if (in_array(strtolower($record->status), [Project::STATUS_ON_HOLD, Project::STATUS_COMPLETED, Project::STATUS_ARCHIVED, Project::STATUS_CANCELLED], true)) {
                return $this->error("Cannot modify members on a project with status '{$record->status}'.", 422);
            }
            return $this->error('You do not have permission to manage members on this project.', 403);
        }

        $validated = $request->validate([
            'project_role' => ['required', 'string', 'max:100'],
        ]);

        $projectMember = $record->projectMembers()->where('user_id', $member)->first();
        if (! $projectMember) {
            return $this->error('Member not found in this project.', 404);
        }

        $user = User::find($member);
        $oldRole = $projectMember->project_role;
        $newRole = $validated['project_role'];

        DB::transaction(function () use ($projectMember, $record, $user, $oldRole, $newRole, $actor) {
            $projectMember->update(['project_role' => $newRole]);

            $userName = $user ? $user->name : "User #{$projectMember->user_id}";
            $record->recordActivity(
                action: 'member_updated',
                description: "{$userName}'s project role changed from {$oldRole} to {$newRole} by {$actor->name}",
                userId: $actor->id,
                metadata: ['user_id' => $user?->id, 'old_role' => $oldRole, 'new_role' => $newRole]
            );
        });

        return $this->success(null, 'Member role updated');
    }

    /** DELETE /projects/{project}/members/{member} */
    public function removeMember(Request $request, int $project, int $member): JsonResponse
    {
        $actor = $request->user();
        $record = Project::where('company_id', $this->companyId($request))->findOrFail($project);

        if (! $this->access->canManageProjectTeam($actor, $record)) {
            if (in_array(strtolower($record->status), [Project::STATUS_ON_HOLD, Project::STATUS_COMPLETED, Project::STATUS_ARCHIVED, Project::STATUS_CANCELLED], true)) {
                return $this->error("Cannot remove members from a project with status '{$record->status}'.", 422);
            }
            return $this->error('You do not have permission to manage members on this project.', 403);
        }

        if (! $record->members()->where('users.id', $member)->exists()) {
            return $this->error('Member not found in this project.', 404);
        }

        $user = User::find($member);

        DB::transaction(function () use ($record, $member, $user, $actor) {
            $record->members()->detach($member);

            $userName = $user ? $user->name : "User #{$member}";
            $record->recordActivity(
                action: 'member_removed',
                description: "{$userName} removed from project by {$actor->name}",
                userId: $actor->id,
                field: 'members',
                oldValue: (string) $member,
                metadata: ['user_id' => $member, 'user_name' => $userName]
            );

            if ($user) {
                event(new ProjectMemberRemovedEvent($record, $user, $actor));
            }
        });

        return $this->success(null, 'Member removed from project');
    }

    /** GET /projects/{project}/activities */
    public function activities(Request $request, int $project): JsonResponse
    {
        $actor = $request->user();
        $record = $this->findProjectForActor($request, $project, 'projects.view');

        if (! $this->access->canViewProjectActivity($actor, $record)) {
            return $this->error('You do not have permission to view this project\'s activity history.', 403);
        }

        $paginator = $record->activities()
            ->with('user:id,name,email')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        $items = $paginator->getCollection()
            ->map(fn ($act) => (new ProjectActivityResource($act))->resolve())
            ->values()->all();

        return $this->paginated($paginator, $items);
    }

    private function findProjectForActor(Request $request, int $id, string $permission): Project
    {
        $actor = $request->user();
        $project = Project::where('company_id', $this->companyId($request))->findOrFail($id);

        if (! $this->access->canAccessProject($actor, $project, $permission)) {
            abort(403, 'You do not have permission to access this project.');
        }

        return $project;
    }

    private function nextProjectCode(int $companyId): string
    {
        $prefix = 'PRJ-';
        $maxProject = Project::withTrashed()
            ->where('company_id', $companyId)
            ->where('code', 'like', "{$prefix}%")
            ->orderByDesc('id')
            ->first();

        $nextNum = 1;
        if ($maxProject && preg_match('/PRJ-(\d+)/', $maxProject->code, $matches)) {
            $nextNum = ((int) $matches[1]) + 1;
        }

        $code = sprintf('%s%03d', $prefix, $nextNum);

        while (Project::withTrashed()->where('company_id', $companyId)->where('code', $code)->exists()) {
            $nextNum++;
            $code = sprintf('%s%03d', $prefix, $nextNum);
        }

        return $code;
    }
}
