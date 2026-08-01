<?php

namespace Shared\Notifications;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
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
     * Jumlah notifikasi in-app yang belum dibaca milik user.
     */
    public function unreadCount(int $userId): int
    {
        $user = User::query()->find($userId);

        return $user === null
            ? 0
            : $user->notifications()->whereNull('read_at')->count();
    }

    /**
     * Notifikasi in-app terbaru milik user, maksimal $limit baris.
     *
     * @return Collection<int, DatabaseNotification>
     */
    public function recent(int $userId, int $limit = 10): Collection
    {
        $user = User::query()->find($userId);

        return $user === null
            ? Collection::empty()
            : $user->notifications()->latest()->limit(max(1, $limit))->get();
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
