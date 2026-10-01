<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_id',
        'leave_type_id',
        'year',
        'total_days',
        'used_days',
        'pending_days',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'total_days' => 'float',
            'used_days' => 'float',
            'pending_days' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function remainingDays(): float
    {
        return max(0.0, (float)$this->total_days - (float)$this->used_days - (float)$this->pending_days);
    }
}
