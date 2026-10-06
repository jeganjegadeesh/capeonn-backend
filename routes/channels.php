<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('conversation.{id}', function ($user, $id) {
    $convId = (int) $id;
    $conversation = Conversation::where('company_id', $user->company_id)->find($convId);
    if (! $conversation) {
        return false;
    }

    return ConversationParticipant::where('conversation_id', $convId)
        ->where('user_id', $user->id)
        ->exists();
}, ['guards' => ['sanctum', 'web']]);

Broadcast::channel('user.{id}', function (User $user, int|string $id) {
    return (int) $user->id === (int) $id;
}, ['guards' => ['sanctum', 'web']]);

Broadcast::channel('project.{id}', function (User $user, int|string $id) {
    $projectId = (int) $id;
    $project = Project::where('company_id', $user->company_id)->find($projectId);
    if (! $project) {
        return false;
    }

    if ($user->isSuperAdmin()) {
        return true;
    }

    if ($project->team_lead_id === $user->id) {
        return true;
    }

    return $project->members()->where('user_id', $user->id)->exists();
}, ['guards' => ['sanctum', 'web']]);
