<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_id',
        'date',
        'clock_in_at',
        'clock_out_at',
        'clock_in_ip',
        'clock_out_ip',
        'clock_in_latitude',
        'clock_in_longitude',
        'clock_out_latitude',
        'clock_out_longitude',
        'clock_in_location_name',
        'clock_out_location_name',
        'is_flagged',
        'flagged_reason',
        'geofence_status',
        'status',
        'total_minutes',
        'break_minutes',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'clock_in_latitude' => 'float',
            'clock_in_longitude' => 'float',
            'clock_out_latitude' => 'float',
            'clock_out_longitude' => 'float',
            'is_flagged' => 'boolean',
            'total_minutes' => 'integer',
            'break_minutes' => 'integer',
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

    public function regularization(): HasOne
    {
        return $this->hasOne(AttendanceRegularization::class);
    }
}
