<?php

namespace Shared\Notifications;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class NotificationService
{
    /**
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $payload
     */
    public function notifyInApp(array $userIds, string $type, array $payload): void
    {
        Notification::sendNow(
            $this->users($userIds),
            new BusinessNotification($type, $payload),
            ['database'],
        );
    }

    /**
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $payload
     */
    public function queueEmailAfterCommit(array $userIds, string $type, array $payload): void
    {
        $userIds = array_values(array_unique($userIds));

        DB::afterCommit(function () use ($userIds, $type, $payload): void {
            try {
                Notification::send(
                    $this->users($userIds),
                    new BusinessNotification($type, $payload),
                );
            } catch (Throwable) {
                Log::error('Email notification enqueue failed.', [
                    'notification_type' => $type,
                    'recipient_ids' => $userIds,
                ]);
            }
        });
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, User>
     */
    private function users(array $userIds): Collection
    {
        return User::query()->whereKey(array_values(array_unique($userIds)))->get();
    }
}
