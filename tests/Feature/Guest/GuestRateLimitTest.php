<?php

namespace Tests\Feature\Guest;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Modules\GuestAccess\Exceptions\GuestAccessDeniedException;
use Modules\GuestAccess\Services\GuestAccessService;
use Tests\TestCase;

/**
 * W6-T3 — three rate-limit layers (logic under the default test cache):
 *
 * 1. Invalid token attempts: 10/min/IP (unknown, malformed, expired).
 * 2. Valid link opens: 60/min/token (scoped per token, not per IP — NAT-safe).
 * 3. Additional-code failures: 5 fails → 15-minute lockout per token+IP.
 *
 * Redis-backed proof lives in GuestRateLimitRedisTest.
 */
class GuestRateLimitTest extends TestCase
{
    use GuestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guestFixtureSetup();
    }

    public function test_eleventh_invalid_token_attempt_from_same_ip_is_throttled(): void
    {
        $service = $this->service();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->assertDenied(fn () => $service->enter(str_repeat(dechex($attempt), 64), null), throttled: false);
        }

        try {
            $service->enter(str_repeat('f', 64), null);
            $this->fail('11th invalid attempt must be throttled.');
        } catch (GuestAccessDeniedException $exception) {
            $this->assertSame('GUEST_DENIED', $exception->getMessage());
            $this->assertTrue($exception->isThrottled);
        }
    }

    public function test_expired_token_counts_toward_invalid_ip_budget(): void
    {
        $service = $this->service();

        for ($attempt = 1; $attempt <= 9; $attempt++) {
            $this->assertDenied(fn () => $service->enter(str_repeat(dechex($attempt), 64), null), throttled: false);
        }

        ['token' => $expiredToken, 'link_id' => $linkId] = $this->approveLink();
        $this->expireLink($linkId);

        $this->assertDenied(fn () => $service->enter($expiredToken, null), throttled: false);
        $this->assertDenied(fn () => $service->enter($expiredToken, null), throttled: true);
    }

    public function test_valid_budget_is_60_per_token_and_scoped_per_token_not_ip(): void
    {
        $service = $this->service();
        ['token' => $tokenA] = $this->approveLink();

        $session = $service->enter($tokenA, null);
        for ($request = 2; $request <= 60; $request++) {
            $this->assertSame($this->containerId, $service->currentSession()->containerId);
        }

        // 61st request against the same token is throttled…
        $this->assertDenied(fn () => $service->currentSession(), throttled: true);

        // …but a second token from the same IP (NAT office) still works.
        ['token' => $tokenB] = $this->approveLink();
        $this->assertSame($this->containerId, $service->enter($tokenB, null)->containerId);
        $this->assertSame($this->containerId, $service->currentSession()->containerId);
    }

    public function test_code_lockout_lasts_fifteen_minutes(): void
    {
        $service = $this->service();
        ['token' => $token] = $this->approveLink(code: 'correct');
        $key = 'guest:code:'.hash('sha256', $token).':'.request()->ip();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->assertDenied(fn () => $service->enter($token, 'wrong-'.$attempt), throttled: false);
        }

        $this->assertTrue(RateLimiter::tooManyAttempts($key, 5));
        $this->assertGreaterThanOrEqual(850, RateLimiter::availableIn($key));
        $this->assertLessThanOrEqual(900, RateLimiter::availableIn($key));
    }

    private function expireLink(int $linkId): void
    {
        DB::table('guest_link')->where('id', $linkId)->update([
            'tanggal_kadaluarsa' => now()->subMinute(),
        ]);
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
