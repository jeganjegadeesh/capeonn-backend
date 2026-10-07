<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Upload extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'user_id',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'disk',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getDownloadUrlAttribute(): string
    {
        return url("/api/v1/uploads/{$this->id}/download");
    }
}
