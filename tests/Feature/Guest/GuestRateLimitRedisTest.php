<?php

namespace Tests\Feature\Guest;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Modules\GuestAccess\Exceptions\GuestAccessDeniedException;
use Modules\GuestAccess\Services\GuestAccessService;
use Tests\TestCase;

/**
 * W6-T3 — rate limits proven against the Redis cache store (REDIS_CACHE_DB=15,
 * local Redis 7, noeviction). Same thresholds as the array-backed suite.
 */
class GuestRateLimitRedisTest extends TestCase
{
    use GuestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guestFixtureSetup();
        Redis::connection('cache')->flushdb();
        Cache::setDefaultDriver('redis');
    }

    public function test_invalid_budget_enforced_with_redis_cache(): void
    {
        $service = $this->service();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->assertDenied(fn () => $service->enter(str_repeat(dechex($attempt), 64), null), throttled: false);
        }

        $this->assertDenied(fn () => $service->enter(str_repeat('f', 64), null), throttled: true);
    }

    public function test_valid_budget_enforced_with_redis_cache(): void
    {
        $service = $this->service();
        ['token' => $token] = $this->approveLink();
        $service->enter($token, null);

        for ($request = 2; $request <= 60; $request++) {
            $service->currentSession();
        }

        $this->assertDenied(fn () => $service->currentSession(), throttled: true);
    }

    public function test_code_lockout_enforced_with_redis_cache(): void
    {
        $service = $this->service();
        ['token' => $token] = $this->approveLink(code: 'correct');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->assertDenied(fn () => $service->enter($token, 'wrong-'.$attempt), throttled: false);
        }

        $this->assertDenied(fn () => $service->enter($token, 'correct'), throttled: true);
    }

    private function assertDenied(callable $call, bool $throttled): void
    {
        try {
            $call();
            $this->fail('Expected GuestAccessDeniedException.');
        } catch (GuestAccessDeniedException $exception) {
            $this->assertSame('GUEST_DENIED', $exception->getMessage());
            $this->assertSame($throttled, $exception->isThrottled);
        }
    }

    private function service(): GuestAccessService
    {
        return app(GuestAccessService::class);
    }
}
