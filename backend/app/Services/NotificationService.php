<?php
namespace App\Services;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Support\Collection;

class NotificationService
{
    public function sendToUser(User $user, string $type, string $title, string $message, ?string $actionUrl = null, array $data = []): AppNotification
    {
        return AppNotification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'action_url' => $actionUrl,
            'data_json' => $data,
        ]);
    }

    public function sendToRoles(array $roles, string $type, string $title, string $message, ?string $actionUrl = null, array $data = []): Collection
    {
        return User::whereIn('role', $roles)->where('is_active', true)->get()->map(fn ($user) =>
            $this->sendToUser($user, $type, $title, $message, $actionUrl, $data)
        );
    }
}
