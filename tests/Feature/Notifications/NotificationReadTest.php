<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Shared\Notifications\NotificationService;
use Tests\TestCase;

class NotificationReadTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $notifications;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->notifications = app(NotificationService::class);
    }

    public function test_unread_count_is_zero_initially(): void
    {
        $user = User::factory()->active()->create();

        $this->assertSame(0, $this->notifications->unreadCount((int) $user->id));
        $this->assertTrue($this->notifications->recent((int) $user->id)->isEmpty());
    }

    public function test_unread_count_and_recent_after_notify_in_app(): void
    {
        $recipient = User::factory()->active()->create();
        $other = User::factory()->active()->create();

        $this->notifications->notifyInApp([(int) $recipient->id], 'CANDIDATE_SUBMITTED', ['candidate_id' => 1]);

        $this->assertSame(1, $this->notifications->unreadCount((int) $recipient->id));
        $this->assertSame(0, $this->notifications->unreadCount((int) $other->id));

        $recent = $this->notifications->recent((int) $recipient->id);
        $this->assertCount(1, $recent);
        $this->assertSame('CANDIDATE_SUBMITTED', $recent->first()->type);
        $this->assertTrue($recent->first()->read_at === null);
        $this->assertNotEmpty($recent->first()->data);
    }

    public function test_recent_orders_latest_first_and_respects_limit(): void
    {
        $user = User::factory()->active()->create();

        $now = Carbon::parse('2026-08-01 10:00:00', 'UTC');
        foreach (['A', 'B', 'C'] as $i => $letter) {
            Carbon::setTestNow($now->copy()->addSeconds($i));
            $this->notifications->notifyInApp([(int) $user->id], 'TYPE_'.$letter, []);
        }
        Carbon::setTestNow();

        $this->assertSame(3, $this->notifications->unreadCount((int) $user->id));

        $limited = $this->notifications->recent((int) $user->id, 2);
        $this->assertCount(2, $limited);
        $this->assertSame('TYPE_C', $limited->first()->type);
        $this->assertSame('TYPE_B', $limited->get(1)->type);
    }

    public function test_marking_read_updates_unread_count(): void
    {
        $user = User::factory()->active()->create();

        $this->notifications->notifyInApp([(int) $user->id], 'CANDIDATE_REVISION_SUBMITTED', []);

        $notification = $user->notifications()->first();
        $notification->markAsRead();

        $this->assertSame(0, $this->notifications->unreadCount((int) $user->id));
        $this->assertFalse($this->notifications->recent((int) $user->id)->first()->read_at === null);
    }

    public function test_unknown_user_returns_empty_read_state(): void
    {
        $this->assertSame(0, $this->notifications->unreadCount(999999));
        $this->assertTrue($this->notifications->recent(999999)->isEmpty());
    }
}
