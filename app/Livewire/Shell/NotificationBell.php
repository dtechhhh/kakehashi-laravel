<?php

namespace App\Livewire\Shell;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Shared\Notifications\NotificationService;

/**
 * Presentation-only in-app notification bell.
 *
 * Polling only (max 60 s) via wire:poll — no WebSocket. Payload detail is
 * never rendered; only the localized type label and timestamp are shown.
 */
final class NotificationBell extends Component
{
    public int $unreadCount = 0;

    /**
     * @var list<array{id: string, label: string, time: string|null, read: bool}>
     */
    public array $items = [];

    public bool $open = false;

    public function mount(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        $userId = Auth::id();

        if ($userId === null) {
            $this->unreadCount = 0;
            $this->items = [];

            return;
        }

        $notifications = app(NotificationService::class);

        $this->unreadCount = $notifications->unreadCount((int) $userId);

        $this->items = $notifications->recent((int) $userId, 5)
            ->map(fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'label' => trans('ui.notifications.'.$notification->type, [], app()->getLocale()),
                'time' => $notification->created_at?->format(__('ui.date_time_format')),
                'read' => $notification->read_at !== null,
            ])
            ->all();
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function render()
    {
        return view('livewire.shell.notification-bell');
    }
}
