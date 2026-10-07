<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Company-wide password policy: 8+ chars, upper + lower case, at least one number.
        Password::defaults(fn () => Password::min(8)->mixedCase()->numbers());

        // 5 login attempts per minute per email + IP.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip());
        });

        // Forgot/reset password: 5 requests per minute per IP.
        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Project Event Listeners
        \Illuminate\Support\Facades\Event::listen(\App\Events\Projects\ProjectAssignedEvent::class, [\App\Listeners\ProjectNotificationListener::class, 'handleProjectAssigned']);
        \Illuminate\Support\Facades\Event::listen(\App\Events\Projects\ProjectCompletionRequestedEvent::class, [\App\Listeners\ProjectNotificationListener::class, 'handleProjectCompletionRequested']);
        \Illuminate\Support\Facades\Event::listen(\App\Events\Projects\ProjectDeadlineApproachingEvent::class, [\App\Listeners\ProjectNotificationListener::class, 'handleProjectDeadlineApproaching']);
        \Illuminate\Support\Facades\Event::listen(\App\Events\Projects\ProjectOverdueEvent::class, [\App\Listeners\ProjectNotificationListener::class, 'handleProjectOverdue']);
        \Illuminate\Support\Facades\Event::listen(\App\Events\Projects\ProjectMemberAddedEvent::class, [\App\Listeners\ProjectNotificationListener::class, 'handleProjectMemberAdded']);
        \Illuminate\Support\Facades\Event::listen(\App\Events\Projects\ProjectMemberRemovedEvent::class, [\App\Listeners\ProjectNotificationListener::class, 'handleProjectMemberRemoved']);
        \Illuminate\Support\Facades\Event::listen(\App\Events\Projects\ProjectStatusChangedEvent::class, [\App\Listeners\ProjectNotificationListener::class, 'handleProjectStatusChanged']);

        // Task Event Listeners
        \Illuminate\Support\Facades\Event::listen(\App\Events\Tasks\TaskAssignedEvent::class, [\App\Listeners\TaskNotificationListener::class, 'handleTaskAssigned']);
        \Illuminate\Support\Facades\Event::listen(\App\Events\Tasks\TaskSubmittedForReviewEvent::class, [\App\Listeners\TaskNotificationListener::class, 'handleTaskSubmittedForReview']);
        \Illuminate\Support\Facades\Event::listen(\App\Events\Tasks\TaskChangesRequestedEvent::class, [\App\Listeners\TaskNotificationListener::class, 'handleTaskChangesRequested']);
        \Illuminate\Support\Facades\Event::listen(\App\Events\Tasks\TaskCompletedEvent::class, [\App\Listeners\TaskNotificationListener::class, 'handleTaskCompleted']);
        \Illuminate\Support\Facades\Event::listen(\App\Events\Tasks\TaskReopenedEvent::class, [\App\Listeners\TaskNotificationListener::class, 'handleTaskReopened']);
        \Illuminate\Support\Facades\Event::listen(\App\Events\Tasks\TaskOverdueEvent::class, [\App\Listeners\TaskNotificationListener::class, 'handleTaskOverdue']);
        \Illuminate\Support\Facades\Event::listen(\App\Events\Tasks\TaskDueSoonEvent::class, [\App\Listeners\TaskNotificationListener::class, 'handleTaskDueSoon']);

        // Phase 6 Chat & File Listeners
        \Illuminate\Support\Facades\Event::listen(\App\Events\Projects\ProjectFileUploadedEvent::class, [\App\Listeners\ProjectNotificationListener::class, 'handleProjectFileUploaded']);
        \Illuminate\Support\Facades\Event::listen(\App\Events\Chat\MessageSentEvent::class, [\App\Listeners\ChatNotificationListener::class, 'handleMessageSent']);
    }
}