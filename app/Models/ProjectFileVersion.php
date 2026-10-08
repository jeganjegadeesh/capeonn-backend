<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectFileVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_file_id',
        'version',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'description',
        'uploaded_by_id',
    ];

    public function projectFile(): BelongsTo
    {
        return $this->belongsTo(ProjectFile::class, 'project_file_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
