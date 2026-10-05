<?php

namespace App\Http\Requests\Tasks;

use App\Models\TimeEntry;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class TimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'started_at'  => ['required', 'date', 'before_or_equal:now'],
            'ended_at'    => ['required', 'date', 'after:started_at', 'before_or_equal:now'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'started_at.before_or_equal' => 'Start time cannot be in the future.',
            'ended_at.before_or_equal'   => 'End time cannot be in the future.',
            'ended_at.after'             => 'End time must be after start time.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $startedAt = $this->input('started_at');
            $endedAt = $this->input('ended_at');

            if ($startedAt && $endedAt) {
                try {
                    $start = Carbon::parse($startedAt);
                    $end = Carbon::parse($endedAt);

                    // Duration limit: 24 hours (86,400 seconds)
                    if ($start->diffInSeconds($end) > 86400) {
                        $validator->errors()->add('ended_at', 'Manual time entry duration cannot exceed 24 hours.');
                    }

                    // Overlap check for user's existing completed time entries
                    $userId = $this->user()?->id;
                    if ($userId) {
                        $overlap = TimeEntry::where('user_id', $userId)
                            ->completed()
                            ->where(function ($q) use ($start, $end) {
                                $q->where(function ($inner) use ($start, $end) {
                                    $inner->where('started_at', '<', $end)
                                          ->where('ended_at', '>', $start);
                                });
                            })
                            ->exists();

                        if ($overlap) {
                            $this->attributes->set('has_overlap_warning', true);
                        }
                    }
                } catch (\Throwable) {
                    // Date parsing error handled by date rule
                }
            }
        });
    }
}
