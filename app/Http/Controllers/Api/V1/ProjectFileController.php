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
        $fileName = $uploadedFile->getClientOriginalName();
        $fileSize = $uploadedFile->getSize();
        $mimeType = $uploadedFile->getMimeType() ?: 'application/octet-stream';

        $path = $uploadedFile->store("projects/{$projectId}", 'public');

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
                'description' => $request->input('description'),
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
