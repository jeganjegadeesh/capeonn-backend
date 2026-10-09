<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TaskCommentAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'task_comment_id',
        'upload_id',
        'file_name',
        'file_path',
        'file_size',
        'mime_type',
        'disk',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    protected $appends = ['url', 'download_url', 'is_image', 'is_pdf'];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(TaskComment::class, 'task_comment_id');
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    public function getUrlAttribute(): string
    {
        $comment = $this->comment;
        $taskId = $comment?->task_id ?? 0;
        $commentId = $this->task_comment_id;

        return url("/api/v1/tasks/{$taskId}/comments/{$commentId}/attachments/{$this->id}/download");
    }

    public function getDownloadUrlAttribute(): string
    {
        return $this->getUrlAttribute();
    }

    public function getIsImageAttribute(): bool
    {
        return Str::startsWith($this->mime_type, 'image/');
    }

    public function getIsPdfAttribute(): bool
    {
        return $this->mime_type === 'application/pdf';
    }
}
