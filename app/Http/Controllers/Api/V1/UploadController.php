<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChatAttachment;
use App\Models\Upload;
use App\Services\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UploadController extends Controller
{
    public function __construct(private readonly AccessControl $accessControl)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:20480', // 20 MB (value in KB)
                'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,tar,gz',
            ],
        ]);

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

        // Store securely on private local disk
        $path = $uploadedFile->store('chat_uploads', 'local');

        $upload = Upload::create([
            'company_id' => $request->user()->company_id,
            'user_id' => $request->user()->id,
            'file_path' => $path,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'disk' => 'local',
        ]);

        return $this->success([
            'id' => $upload->id,
            'path' => $upload->file_path,
            'file_name' => $upload->file_name,
            'file_size' => $upload->file_size,
            'mime_type' => $upload->mime_type,
            'url' => url("/api/v1/uploads/{$upload->id}/download"),
            'download_url' => url("/api/v1/uploads/{$upload->id}/download"),
            'preview_url' => url("/api/v1/uploads/{$upload->id}/download?preview=1"),
        ], 'File uploaded successfully', 201);
    }

    /**
     * Download or preview an uploaded file.
     */
    public function download(Request $request, int $uploadId): StreamedResponse|JsonResponse|BinaryFileResponse
    {
        $actor = $request->user();

        $upload = Upload::where('company_id', $actor->company_id)->findOrFail($uploadId);

        // Access check: owner can download; or anyone in conversation where attachment is linked
        if ((int) $upload->user_id !== (int) $actor->id) {
            $attachment = ChatAttachment::where('file_path', $upload->file_path)->first();
            if ($attachment && $attachment->message && $attachment->message->conversation) {
                if (! $this->accessControl->canAccessConversation($actor, $attachment->message->conversation)) {
                    return $this->error('You do not have access to this upload.', 403);
                }
            } else {
                return $this->error('You do not have access to this upload.', 403);
            }
        }

        $disk = Storage::disk($upload->disk ?: 'local');
        if (! $disk->exists($upload->file_path)) {
            // Check public disk fallback
            if (Storage::disk('public')->exists($upload->file_path)) {
                $disk = Storage::disk('public');
            } else {
                return $this->error('File not found on storage disk.', 404);
            }
        }

        if ($request->boolean('preview') || $request->query('inline')) {
            $headers = [
                'Content-Type' => $upload->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="' . addslashes($upload->file_name) . '"',
            ];
            return Storage::disk($upload->disk ?: 'local')->response($upload->file_path, $upload->file_name, $headers);
        }

        return $disk->download($upload->file_path, $upload->file_name);
    }
}