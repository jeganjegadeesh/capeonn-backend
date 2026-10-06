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
        'description',
    ];

    protected $appends = ['url', 'formatted_size'];

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
        $path = Storage::disk('public')->url($this->file_path);
        return str_starts_with($path, 'http') ? $path : url($path);
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
