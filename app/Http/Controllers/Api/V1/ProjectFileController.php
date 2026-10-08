<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\Projects\ProjectFileUploadedEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\ProjectFileUploadRequest;
use App\Http\Resources\ProjectFileResource;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectFile;
use App\Services\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectFileController extends Controller
{
    public function __construct(private readonly AccessControl $accessControl)
    {
    }

    /**
     * List files in a project workspace.
     */
    public function index(Request $request, int $projectId): JsonResponse
    {
        $actor = $request->user();

        $project = Project::where('company_id', $actor->company_id)->findOrFail($projectId);

        if (! $this->accessControl->canAccessProjectFiles($actor, $project)) {
            return $this->error('You do not have permission to view files for this project.', 403);
        }

        $query = ProjectFile::where('project_id', $projectId)
            ->with(['uploader.role', 'task:id,title']);

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        if ($taskId = $request->query('task_id')) {
            $query->where('task_id', (int) $taskId);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('file_name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $paginator = $query->orderByDesc('id')->paginate($this->perPage($request));

        $items = ProjectFileResource::collection($paginator->items())->resolve();

        return $this->paginated(
            $paginator,
            $items,
            'Project files loaded'
        );
    }

    /**
     * Upload a file to project workspace (or task).
     */
    public function store(ProjectFileUploadRequest $request, int $projectId): JsonResponse
    {
        $actor = $request->user();

        $project = Project::where('company_id', $actor->company_id)->findOrFail($projectId);

        if (! $this->accessControl->canUploadProjectFile($actor, $project)) {
            return $this->error('You cannot upload files to this project. The project may be closed or you lack permission.', 403);
        }

        $uploadedFile = $request->file('file');
        $extension = strtolower($uploadedFile->getClientOriginalExtension());

        // Dangerous executable extensions blacklist
        $blockedExtensions = ['exe', 'bat', 'cmd', 'sh', 'php', 'phtml', 'cgi', 'pl', 'py', 'js', 'msi', 'com', 'vbs', 'ps1', 'jar'];
        if (in_array($extension, $blockedExtensions, true)) {
            return $this->error('Executable and script file uploads are not permitted.', 422);
        }

        $fileName = $uploadedFile->getClientOriginalName();
        $fileSize = $uploadedFile->getSize();
        $mimeType = $uploadedFile->getMimeType() ?: 'application/octet-stream';

        // Save privately on local disk
        $path = $uploadedFile->store("projects/{$projectId}", 'local');

        $projectFile = DB::transaction(function () use ($project, $actor, $request, $fileName, $path, $fileSize, $mimeType) {
            $file = ProjectFile::create([
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'task_id' => $request->input('task_id'),
                'uploaded_by_id' => $actor->id,
                'file_name' => $fileName,
                'file_path' => $path,
                'file_size' => $fileSize,
                'mime_type' => $mimeType,
                'category' => $request->input('category', ProjectFile::CATEGORY_GENERAL),
                'version' => 1,
                'description' => $request->input('description'),
            ]);

            // Create initial version history record
            \App\Models\ProjectFileVersion::create([
                'project_file_id' => $file->id,
                'version' => 1,
                'file_path' => $path,
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'mime_type' => $mimeType,
                'description' => $request->input('description'),
                'uploaded_by_id' => $actor->id,
            ]);

            // Append to Project Activity audit log
            ProjectActivity::create([
                'project_id' => $project->id,
                'task_id' => $file->task_id,
                'user_id' => $actor->id,
                'action' => 'file_uploaded',
                'field' => 'file',
                'old_value' => null,
                'new_value' => $fileName,
                'description' => "Uploaded file '{$fileName}'",
                'metadata' => [
                    'file_id' => $file->id,
                    'file_name' => $fileName,
                    'file_size' => $fileSize,
                    'category' => $file->category,
                ],
                'created_at' => now(),
            ]);

            return $file;
        });

        $projectFile->loadMissing(['uploader.role', 'task:id,title']);

        event(new ProjectFileUploadedEvent($projectFile, $actor));

        return $this->success(
            new ProjectFileResource($projectFile),
            'File uploaded successfully',
            201
        );
    }

    /**
     * Authorized download or preview of a project file.
     */
    public function download(Request $request, int $projectId, int $fileId): StreamedResponse|JsonResponse|BinaryFileResponse
    {
        $actor = $request->user();

        $project = Project::where('company_id', $actor->company_id)->findOrFail($projectId);
        $file = ProjectFile::where('project_id', $projectId)->findOrFail($fileId);

        if (! $this->accessControl->canAccessProjectFiles($actor, $project)) {
            return $this->error('You do not have permission to download this project file.', 403);
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($file->file_path)) {
            return $this->error('File not found on storage disk.', 404);
        }

        if ($request->boolean('preview') || $request->query('inline')) {
            $headers = [
                'Content-Type' => $file->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="' . addslashes($file->file_name) . '"',
                'X-Content-Type-Options' => 'nosniff',
            ];
            return $disk->response($file->file_path, $file->file_name, $headers);
        }

        return $disk->download($file->file_path, $file->file_name);
    }

    /**
     * Update file metadata (description, rename, category).
     */
    public function update(Request $request, int $projectId, int $fileId): JsonResponse
    {
        $actor = $request->user();

        $project = Project::where('company_id', $actor->company_id)->findOrFail($projectId);
        $file = ProjectFile::where('project_id', $projectId)->findOrFail($fileId);

        if (! $this->accessControl->canDeleteProjectFile($actor, $file)) {
            return $this->error('You do not have permission to edit this file metadata.', 403);
        }

        $validated = $request->validate([
            'file_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category' => ['nullable', 'string', 'in:general,specification,design,document,report,archive'],
        ]);

        if (! empty($validated['file_name'])) {
            $originalExt = strtolower(pathinfo($file->file_name, PATHINFO_EXTENSION));
            $newName = $validated['file_name'];
            $newExt = strtolower(pathinfo($newName, PATHINFO_EXTENSION));
            $newBase = pathinfo($newName, PATHINFO_FILENAME);

            $blockedExtensions = ['exe', 'bat', 'cmd', 'sh', 'php', 'phtml', 'cgi', 'pl', 'py', 'js', 'msi', 'com', 'vbs', 'ps1', 'jar'];
            if (in_array($newExt, $blockedExtensions, true)) {
                return $this->error('Executable and script file extensions are not permitted.', 422);
            }

            // Preserve original extension if changed or stripped
            $targetName = ($originalExt && $newExt !== $originalExt) ? "{$newBase}.{$originalExt}" : $newName;
            $file->file_name = $targetName;
        }

        if ($request->has('description')) {
            $file->description = $validated['description'];
        }
        if ($request->has('category') && ! empty($validated['category'])) {
            $file->category = $validated['category'];
        }
        $file->save();

        $file->loadMissing(['uploader.role', 'task:id,title']);

        return $this->success(new ProjectFileResource($file), 'File updated successfully');
    }

    /**
     * Replace file with a new version.
     */
    public function version(Request $request, int $projectId, int $fileId): JsonResponse
    {
        $actor = $request->user();

        $project = Project::where('company_id', $actor->company_id)->findOrFail($projectId);
        $file = ProjectFile::where('project_id', $projectId)->findOrFail($fileId);

        if (! $this->accessControl->canUploadProjectFile($actor, $project)) {
            return $this->error('Project is closed or you do not have permission to upload new versions.', 403);
        }

        // Restrict replacing to original uploader, Team Lead, and Manager / Super Admin
        $canReplace = (int) $file->uploaded_by_id === (int) $actor->id
            || (int) $project->team_lead_id === (int) $actor->id
            || $this->accessControl->canManageProject($actor, $project)
            || $actor->hasRole(\App\Models\Role::SUPER_ADMIN, 'admin');

        if (! $canReplace) {
            return $this->error('Only the uploader, Team Lead, or Manager can replace this file.', 403);
        }

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:20480',
                'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,tar,gz',
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $uploadedFile = $request->file('file');
        $extension = strtolower($uploadedFile->getClientOriginalExtension());
        $blockedExtensions = ['exe', 'bat', 'cmd', 'sh', 'php', 'phtml', 'cgi', 'pl', 'py', 'js', 'msi', 'com', 'vbs', 'ps1', 'jar'];
        if (in_array($extension, $blockedExtensions, true)) {
            return $this->error('Executable and script file uploads are not permitted.', 422);
        }

        $newPath = $uploadedFile->store("projects/{$projectId}", 'local');
        $oldVersion = $file->version ?? 1;
        $newVersion = $oldVersion + 1;

        // Archive previous version if not already stored
        if (! \App\Models\ProjectFileVersion::where('project_file_id', $file->id)->where('version', $oldVersion)->exists()) {
            \App\Models\ProjectFileVersion::create([
                'project_file_id' => $file->id,
                'version' => $oldVersion,
                'file_path' => $file->file_path,
                'file_name' => $file->file_name,
                'file_size' => $file->file_size,
                'mime_type' => $file->mime_type,
                'description' => $file->description,
                'uploaded_by_id' => $file->uploaded_by_id,
            ]);
        }

        // Create new version entry
        \App\Models\ProjectFileVersion::create([
            'project_file_id' => $file->id,
            'version' => $newVersion,
            'file_path' => $newPath,
            'file_name' => $uploadedFile->getClientOriginalName(),
            'file_size' => $uploadedFile->getSize(),
            'mime_type' => $uploadedFile->getMimeType() ?: 'application/octet-stream',
            'description' => $request->input('description', $file->description),
            'uploaded_by_id' => $actor->id,
        ]);

        $file->update([
            'file_path' => $newPath,
            'file_name' => $uploadedFile->getClientOriginalName(),
            'file_size' => $uploadedFile->getSize(),
            'mime_type' => $uploadedFile->getMimeType() ?: 'application/octet-stream',
            'version' => $newVersion,
            'uploaded_by_id' => $actor->id,
            'description' => $request->input('description', $file->description),
        ]);

        ProjectActivity::create([
            'project_id' => $project->id,
            'task_id' => $file->task_id,
            'user_id' => $actor->id,
            'action' => 'file_version_updated',
            'field' => 'version',
            'old_value' => (string) $oldVersion,
            'new_value' => (string) $newVersion,
            'description' => "Uploaded new version (v{$newVersion}) of file '{$file->file_name}'",
            'metadata' => [
                'file_id' => $file->id,
                'version' => $newVersion,
            ],
            'created_at' => now(),
        ]);

        $file->loadMissing(['uploader.role', 'task:id,title']);

        return $this->success(new ProjectFileResource($file), 'File version updated successfully');
    }

    /**
     * List all historical versions of a project file.
     */
    public function versions(Request $request, int $projectId, int $fileId): JsonResponse
    {
        $actor = $request->user();

        $project = Project::where('company_id', $actor->company_id)->findOrFail($projectId);
        $file = ProjectFile::where('project_id', $projectId)->findOrFail($fileId);

        if (! $this->accessControl->canAccessProjectFiles($actor, $project)) {
            return $this->error('You do not have permission to view file versions.', 403);
        }

        $versions = $file->versions()->with('uploader.role')->get();

        return $this->success(
            \App\Http\Resources\ProjectFileVersionResource::collection($versions),
            'File versions loaded'
        );
    }

    /**
     * Download a specific historical version of a project file.
     */
    public function downloadVersion(Request $request, int $projectId, int $fileId, int $versionId): StreamedResponse|JsonResponse|BinaryFileResponse
    {
        $actor = $request->user();

        $project = Project::where('company_id', $actor->company_id)->findOrFail($projectId);
        $file = ProjectFile::where('project_id', $projectId)->findOrFail($fileId);

        if (! $this->accessControl->canAccessProjectFiles($actor, $project)) {
            return $this->error('You do not have permission to download this file version.', 403);
        }

        $version = \App\Models\ProjectFileVersion::where('project_file_id', $file->id)->findOrFail($versionId);

        $disk = Storage::disk('local');
        if (! $disk->exists($version->file_path)) {
            return $this->error('Version file not found on storage disk.', 404);
        }

        if ($request->boolean('preview') || $request->query('inline')) {
            $headers = [
                'Content-Type' => $version->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="' . addslashes($version->file_name) . '"',
                'X-Content-Type-Options' => 'nosniff',
            ];
            return $disk->response($version->file_path, $version->file_name, $headers);
        }

        return $disk->download($version->file_path, $version->file_name);
    }

    /**
     * Restore an older version as the active file version.
     */
    public function restoreVersion(Request $request, int $projectId, int $fileId, int $versionId): JsonResponse
    {
        $actor = $request->user();

        $project = Project::where('company_id', $actor->company_id)->findOrFail($projectId);
        $file = ProjectFile::where('project_id', $projectId)->findOrFail($fileId);

        $canReplace = (int) $file->uploaded_by_id === (int) $actor->id
            || (int) $project->team_lead_id === (int) $actor->id
            || $this->accessControl->canManageProject($actor, $project)
            || $actor->hasRole(\App\Models\Role::SUPER_ADMIN, 'admin');

        if (! $canReplace) {
            return $this->error('Only the uploader, Team Lead, or Manager can restore file versions.', 403);
        }

        $targetVersion = \App\Models\ProjectFileVersion::where('project_file_id', $file->id)->findOrFail($versionId);

        $oldVersion = $file->version;
        $newVersion = $file->version + 1;

        if (! \App\Models\ProjectFileVersion::where('project_file_id', $file->id)->where('version', $oldVersion)->exists()) {
            \App\Models\ProjectFileVersion::create([
                'project_file_id' => $file->id,
                'version' => $oldVersion,
                'file_path' => $file->file_path,
                'file_name' => $file->file_name,
                'file_size' => $file->file_size,
                'mime_type' => $file->mime_type,
                'description' => $file->description,
                'uploaded_by_id' => $file->uploaded_by_id,
            ]);
        }

        $restoredExt = pathinfo($targetVersion->file_path, PATHINFO_EXTENSION);
        $restoredPath = "projects/{$projectId}/" . \Illuminate\Support\Str::random(40) . ($restoredExt ? ".{$restoredExt}" : '');
        Storage::disk('local')->copy($targetVersion->file_path, $restoredPath);

        $file->update([
            'file_path' => $restoredPath,
            'file_name' => $targetVersion->file_name,
            'file_size' => $targetVersion->file_size,
            'mime_type' => $targetVersion->mime_type,
            'version' => $newVersion,
            'description' => "Restored from version {$targetVersion->version}",
            'uploaded_by_id' => $actor->id,
        ]);

        \App\Models\ProjectFileVersion::create([
            'project_file_id' => $file->id,
            'version' => $newVersion,
            'file_path' => $restoredPath,
            'file_name' => $targetVersion->file_name,
            'file_size' => $targetVersion->file_size,
            'mime_type' => $targetVersion->mime_type,
            'description' => "Restored from version {$targetVersion->version}",
            'uploaded_by_id' => $actor->id,
        ]);

        return $this->success(
            new ProjectFileResource($file->fresh(['uploader.role', 'task:id,title'])),
            "File restored to version {$targetVersion->version} as v{$newVersion}"
        );
    }

    /**
     * Delete a project file.
     */
    public function destroy(Request $request, int $projectId, int $fileId): JsonResponse
    {
        $actor = $request->user();

        $project = Project::where('company_id', $actor->company_id)->findOrFail($projectId);
        $file = ProjectFile::where('project_id', $projectId)->findOrFail($fileId);

        if (! $this->accessControl->canDeleteProjectFile($actor, $file)) {
            return $this->error('You do not have permission to delete this file.', 403);
        }

        DB::transaction(function () use ($file, $project, $actor) {
            $fileName = $file->file_name;
            $file->delete();

            // Append to Project Activity audit log
            ProjectActivity::create([
                'project_id' => $project->id,
                'task_id' => $file->task_id,
                'user_id' => $actor->id,
                'action' => 'file_deleted',
                'field' => 'file',
                'old_value' => $fileName,
                'new_value' => null,
                'description' => "Deleted file '{$fileName}'",
                'metadata' => [
                    'file_id' => $file->id,
                    'file_name' => $fileName,
                ],
                'created_at' => now(),
            ]);
        });

        return $this->success(null, 'File deleted successfully');
    }
}
