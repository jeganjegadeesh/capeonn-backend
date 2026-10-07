<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class ProjectFile extends Model
{
    use HasFactory, SoftDeletes;

    public const CATEGORY_GENERAL = 'general';
    public const CATEGORY_SPECIFICATION = 'specification';
    public const CATEGORY_DESIGN = 'design';
    public const CATEGORY_DOCUMENT = 'document';
    public const CATEGORY_REPORT = 'report';
    public const CATEGORY_ARCHIVE = 'archive';

    protected $fillable = [
        'company_id',
        'project_id',
        'task_id',
        'uploaded_by_id',
        'file_name',
        'file_path',
        'file_size',
        'mime_type',
        'category',
        'version',
        'description',
    ];

    protected $casts = [
        'version' => 'integer',
        'file_size' => 'integer',
    ];

    protected $appends = ['url', 'download_url', 'preview_url', 'formatted_size'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function getUrlAttribute(): string
    {
        return url("/api/v1/projects/{$this->project_id}/files/{$this->id}/download");
    }

    public function getDownloadUrlAttribute(): string
    {
        return url("/api/v1/projects/{$this->project_id}/files/{$this->id}/download");
    }

    public function getPreviewUrlAttribute(): string
    {
        return url("/api/v1/projects/{$this->project_id}/files/{$this->id}/download?preview=1");
    }

    public function getFormattedSizeAttribute(): string
    {
        $bytes = (int) $this->file_size;
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
