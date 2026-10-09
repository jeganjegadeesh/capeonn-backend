<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'task_id',
        'round_number',
        'submitted_by_id',
        'submitted_at',
        'reviewer_id',
        'decided_at',
        'outcome',
        'feedback',
        'checklist_passed',
    ];

    protected $casts = [
        'round_number'     => 'integer',
        'checklist_passed' => 'boolean',
        'submitted_at'     => 'datetime',
        'decided_at'       => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
