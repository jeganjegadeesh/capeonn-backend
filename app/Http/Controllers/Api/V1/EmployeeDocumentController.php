<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\StoreDocumentRequest;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EmployeeDocumentController extends Controller
{
    public function __construct(private AccessControl $access)
    {
    }

    public function index(Request $request, int $employee): JsonResponse
    {
        $actor = $request->user();
        $target = $this->access->constrainUsers(User::query(), $actor, 'documents.view')->find($employee);

        if (! $target) {
            return $this->error('Employee not found or access denied', 404);
        }

        $docs = EmployeeDocument::where('user_id', $target->id)
            ->with('uploadedBy:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($doc) => [
                'id'            => $doc->id,
                'user_id'       => $doc->user_id,
                'title'         => $doc->title,
                'document_type' => $doc->document_type,
                'file_path'     => $doc->file_path,
                'file_name'     => $doc->file_name,
                'file_size'     => $doc->file_size,
                'mime_type'     => $doc->mime_type,
                'is_verified'   => (bool) $doc->is_verified,
                'verified_by'   => $doc->verifiedBy ? ['id' => $doc->verifiedBy->id, 'name' => $doc->verifiedBy->name] : null,
                'verified_at'   => $doc->verified_at?->toIso8601String(),
                'uploaded_by'   => $doc->uploadedBy ? ['id' => $doc->uploadedBy->id, 'name' => $doc->uploadedBy->name] : null,
                'created_at'    => $doc->created_at->toIso8601String(),
            ]);

        return $this->success($docs);
    }

    public function store(StoreDocumentRequest $request, int $employee): JsonResponse
    {
        $actor = $request->user();
        $target = User::where('company_id', $actor->company_id)->find($employee);

        if (! $target) {
            return $this->error('Employee not found', 404);
        }

        $isSelf = (int) $actor->id === (int) $target->id;
        $canManage = $this->access->canAccessUser($actor, $target, 'documents.manage');

        if (! $isSelf && ! $canManage) {
            return $this->error('You do not have permission to upload documents for this employee', 403);
        }

        $data = $request->validated();

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store('documents/' . $target->id, 'public');
            $fileName = $file->getClientOriginalName();
            $fileSize = $file->getSize();
            $mimeType = $file->getClientMimeType();
        } else {
            $path = $data['file_path'] ?? 'documents/sample.pdf';
            $fileName = $data['file_name'] ?? 'document.pdf';
            $fileSize = (int) ($data['file_size'] ?? 1024);
            $mimeType = $data['mime_type'] ?? 'application/pdf';
        }

        $document = EmployeeDocument::create([
            'user_id'        => $target->id,
            'company_id'     => $actor->company_id,
            'title'          => $data['title'],
            'document_type'  => $data['document_type'],
            'file_path'      => $path,
            'file_name'      => $fileName,
            'file_size'      => $fileSize,
            'mime_type'      => $mimeType,
            'uploaded_by_id' => $actor->id,
        ]);

        return $this->success([
            'id'            => $document->id,
            'user_id'       => $document->user_id,
            'title'         => $document->title,
            'document_type' => $document->document_type,
            'file_path'     => $document->file_path,
            'file_name'     => $document->file_name,
            'file_size'     => $document->file_size,
            'mime_type'     => $document->mime_type,
            'uploaded_by'   => ['id' => $actor->id, 'name' => $actor->name],
            'created_at'    => $document->created_at->toIso8601String(),
        ], 'Document uploaded successfully', 201);
    }

    public function destroy(Request $request, int $document): JsonResponse
    {
        $actor = $request->user();
        $doc = EmployeeDocument::where('company_id', $actor->company_id)->find($document);

        if (! $doc) {
            return $this->error('Document not found', 404);
        }

        $targetUser = User::find($doc->user_id);
        $isSelf = (int) $actor->id === (int) $doc->user_id;
        $canManage = $targetUser ? $this->access->canAccessUser($actor, $targetUser, 'documents.manage') : false;

        if (! $isSelf && ! $canManage) {
            return $this->error('Permission denied', 403);
        }

        if (Storage::disk('public')->exists($doc->file_path)) {
            Storage::disk('public')->delete($doc->file_path);
        }

        $doc->delete();

        return $this->success(null, 'Document deleted successfully');
    }

    /** PUT /documents/{document}/verify (HR & Super Admin only) */
    public function verify(Request $request, int $document): JsonResponse
    {
        $actor = $request->user();
        if (! $actor->isHR() && ! $actor->isSuperAdmin()) {
            return $this->error('Only HR and Super Admin can verify documents', 403);
        }

        $doc = EmployeeDocument::where('company_id', $actor->company_id)->find($document);
        if (! $doc) {
            return $this->error('Document not found', 404);
        }

        $isVerified = $request->boolean('is_verified', true);

        $doc->update([
            'is_verified'    => $isVerified,
            'verified_by_id' => $isVerified ? $actor->id : null,
            'verified_at'    => $isVerified ? now() : null,
        ]);

        return $this->success([
            'id'          => $doc->id,
            'is_verified' => (bool) $doc->is_verified,
            'verified_by' => $doc->verifiedBy ? ['id' => $doc->verifiedBy->id, 'name' => $doc->verifiedBy->name] : null,
            'verified_at' => $doc->verified_at?->toIso8601String(),
        ], $isVerified ? 'Document verified successfully' : 'Document unverified');
    }
}
