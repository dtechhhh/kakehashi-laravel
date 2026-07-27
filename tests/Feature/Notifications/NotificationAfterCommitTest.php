<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\Auth\Rbac;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Shared\Approval\PendingRequest;
use Shared\Approval\PendingRequestService;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Shared\Notifications\BusinessNotification;
use Shared\Notifications\NotificationService;
use Tests\TestCase;

class NotificationAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test membutuhkan commit sungguhan agar callback after-commit dieksekusi.
     *
     * @var list<string>
     */
    protected array $connectionsToTransact = [];

    private PendingRequestService $pending;

    private NotificationService $notifications;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanFixtures();
        $this->seed(RolePermissionSeeder::class);
        $this->pending = app(PendingRequestService::class);
        $this->notifications = app(NotificationService::class);
    }

    protected function tearDown(): void
    {
        $this->cleanFixtures();

        parent::tearDown();
    }

    public function test_mail_configuration_has_no_log_transport_or_fallback(): void
    {
        $template = file_get_contents(base_path('.env.example'));
        $configSource = file_get_contents(config_path('mail.php'));

        $this->assertIsString($template);
        $this->assertIsString($configSource);
        $this->assertMatchesRegularExpression('/^MAIL_MAILER=array$/m', $template);
        $this->assertStringContainsString("'default' => env('MAIL_MAILER', 'array')", $configSource);
        $this->assertNull(config('mail.mailers.log'));
        $this->assertNull(config('mail.mailers.failover'));
        $this->assertStringNotContainsString("'transport' => 'log'", $configSource);
    }

    public function test_queued_worker_uses_array_transport_without_logging_mail_contents(): void
    {
        $queue = Queue::fake();
        $recipient = User::factory()->active()->create([
            'email' => 'worker-recipient@example.test',
        ]);

        DB::transaction(function () use ($recipient): void {
            $this->notifications->queueEmailAfterCommit(
                [$recipient->getKey()],
                ActionType::IC_APPROVED->value,
                ['pending_request_id' => 991],
            );
        });

        /** @var SendQueuedNotifications $job */
        $job = $queue->pushed(SendQueuedNotifications::class)->sole();
        $handler = new TestHandler;
        $this->app->instance(LoggerInterface::class, new Logger('mail-worker', [$handler]));

        /** @var MailManager $mail */
        $mail = $this->app->make(MailManager::class);
        $mail->forgetMailers();

        $job->handle($this->app->make(ChannelManager::class));

        $transport = $mail->mailer()->getSymfonyTransport();
        $logged = json_encode($handler->getRecords(), JSON_THROW_ON_ERROR);

        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $this->assertCount(1, $transport->messages());
        $this->assertStringNotContainsString($recipient->email, $logged);
        $this->assertStringNotContainsString('Content-Type:', $logged);
        $this->assertStringNotContainsString('Notifikasi Kakehashi', $logged);
        $this->assertStringNotContainsString('Ada pembaruan di Kakehashi.', $logged);
    }

    public function test_business_audit_and_in_app_commit_before_redis_email_is_queued(): void
    {
        $queue = Queue::fake();
        [$maker, $checker, $request] = $this->pendingFixture();
        $observer = $this->observer();

        DB::transaction(function () use ($queue, $maker, $checker, $request, $observer): void {
            $this->pending->approve(
                requestId: $request->getKey(),
                checkerId: $checker->getKey(),
                auditAction: ActionType::IC_APPROVED,
            );

            $this->notifications->notifyInApp(
                [$maker->getKey()],
                ActionType::IC_APPROVED->value,
                ['pending_request_id' => $request->getKey()],
            );
            $this->notifications->queueEmailAfterCommit(
                [$maker->getKey()],
                ActionType::IC_APPROVED->value,
                ['pending_request_id' => $request->getKey()],
            );

            $this->assertSame('pending', $this->observedPendingStatus($observer, $request->getKey()));
            $this->assertSame(0, $this->observedApprovalAudit($observer, $request->getKey()));
            $this->assertSame(0, $this->observedNotifications($observer, $maker->getKey()));
            $queue->assertNothingPushed();
        });

        $this->assertSame(PendingStatus::APPROVED, $request->fresh()->status);
        $this->assertSame(1, $this->observedApprovalAudit($observer, $request->getKey()));
        $this->assertDatabaseHas('notifications', [
            'type' => ActionType::IC_APPROVED->value,
            'notifiable_id' => $maker->getKey(),
        ]);

        $queue->assertPushed(
            SendQueuedNotifications::class,
            static fn (SendQueuedNotifications $job): bool => $job->connection === 'redis'
                && $job->afterCommit === true
                && $job->channels === ['mail']
                && $job->notification instanceof BusinessNotification
                && $job->notification->type === ActionType::IC_APPROVED->value
        );
    }

    public function test_rollback_removes_business_audit_and_in_app_and_never_queues_email(): void
    {
        $queue = Queue::fake();
        [$maker, $checker, $request] = $this->pendingFixture();

        try {
            DB::transaction(function () use ($maker, $checker, $request): void {
                $this->pending->approve(
                    requestId: $request->getKey(),
                    checkerId: $checker->getKey(),
                    auditAction: ActionType::IC_APPROVED,
                );
                $this->notifications->notifyInApp(
                    [$maker->getKey()],
                    ActionType::IC_APPROVED->value,
                    ['pending_request_id' => $request->getKey()],
                );
                $this->notifications->queueEmailAfterCommit(
                    [$maker->getKey()],
                    ActionType::IC_APPROVED->value,
                    ['pending_request_id' => $request->getKey()],
                );

                throw new RuntimeException('force rollback');
            });
            $this->fail('transaction must roll back');
        } catch (RuntimeException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        $this->assertSame(PendingStatus::PENDING, $request->fresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::IC_APPROVED->value)->count());
        $this->assertDatabaseCount('notifications', 0);
        $queue->assertNothingPushed();
    }

    public function test_in_app_failure_rolls_back_business_and_audit(): void
    {
        [, $checker, $request] = $this->pendingFixture();

        Notification::shouldReceive('sendNow')
            ->once()
            ->andThrow(new RuntimeException('database notification failed'));

        try {
            DB::transaction(function () use ($checker, $request): void {
                $this->pending->approve(
                    requestId: $request->getKey(),
                    checkerId: $checker->getKey(),
                    auditAction: ActionType::IC_APPROVED,
                );
                $this->notifications->notifyInApp(
                    [$request->requested_by],
                    ActionType::IC_APPROVED->value,
                    ['pending_request_id' => $request->getKey()],
                );
            });
            $this->fail('in-app failure must abort the transaction');
        } catch (RuntimeException $exception) {
            $this->assertSame('database notification failed', $exception->getMessage());
        }

        $this->assertSame(PendingStatus::PENDING, $request->fresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::IC_APPROVED->value)->count());
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_enqueue_failure_is_safe_and_does_not_roll_back_committed_rows(): void
    {
        $queue = Queue::fake();
        $sensitiveFailure = 'redis failed for raw@example.test token=secret';
        $queue->beforePushing(static function () use ($sensitiveFailure): never {
            throw new RuntimeException($sensitiveFailure);
        });
        Log::spy();

        [$maker, $checker, $request] = $this->pendingFixture();

        DB::transaction(function () use ($maker, $checker, $request): void {
            $this->pending->approve(
                requestId: $request->getKey(),
                checkerId: $checker->getKey(),
                auditAction: ActionType::IC_APPROVED,
            );
            $this->notifications->notifyInApp(
                [$maker->getKey()],
                ActionType::IC_APPROVED->value,
                ['pending_request_id' => $request->getKey()],
            );
            $this->notifications->queueEmailAfterCommit(
                [$maker->getKey()],
                ActionType::IC_APPROVED->value,
                ['pending_request_id' => $request->getKey()],
            );
        });

        $this->assertSame(PendingStatus::APPROVED, $request->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action_type', ActionType::IC_APPROVED->value)->count());
        $this->assertDatabaseHas('notifications', [
            'type' => ActionType::IC_APPROVED->value,
            'notifiable_id' => $maker->getKey(),
        ]);
        $queue->assertNothingPushed();

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(static function (string $message, array $context) use ($maker, $sensitiveFailure): bool {
                $logged = json_encode([$message, $context], JSON_THROW_ON_ERROR);

                return $message === 'Email notification enqueue failed.'
                    && $context === [
                        'notification_type' => ActionType::IC_APPROVED->value,
                        'recipient_ids' => [$maker->getKey()],
                    ]
                    && ! str_contains($logged, $sensitiveFailure)
                    && ! str_contains($logged, $maker->email);
            });
    }

    /**
     * @return array{User, User, PendingRequest}
     */
    private function pendingFixture(): array
    {
        $maker = User::factory()->active()->create();
        $maker->assignRole(Rbac::ASSISTANT_MANAGER);

        $checker = User::factory()->active()->create();
        $checker->assignRole(Rbac::JOB_MANAGER);

        $request = $this->pending->submit(
            type: PendingType::IC_CREATE,
            targetType: 'interview_container',
            targetId: 71,
            requestedBy: $maker->getKey(),
            auditAction: ActionType::IC_SUBMITTED,
        );

        return [$maker, $checker, $request];
    }

    private function observer(): Connection
    {
        $observer = DB::connection('pgsql_migrator');

        $this->assertSame(0, $observer->transactionLevel());

        return $observer;
    }

    private function observedPendingStatus(Connection $observer, int $requestId): string
    {
        return (string) $observer->table('pending_request')->where('id', $requestId)->value('status');
    }

    private function observedApprovalAudit(Connection $observer, int $requestId): int
    {
        return (int) $observer->table('audit_log')
            ->where('action_type', ActionType::IC_APPROVED->value)
            ->where('detail->pending_request_id', $requestId)
            ->count();
    }

    private function observedNotifications(Connection $observer, int $userId): int
    {
        return (int) $observer->table('notifications')->where('notifiable_id', $userId)->count();
    }

    private function cleanFixtures(): void
    {
        DB::connection('pgsql_migrator')
            ->statement('TRUNCATE notifications, pending_request, audit_log RESTART IDENTITY');

        DB::table('model_has_roles')->delete();
        DB::table('model_has_permissions')->delete();
        User::query()->delete();
    }
}
