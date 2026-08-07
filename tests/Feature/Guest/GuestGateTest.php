<?php

namespace Tests\Feature\Guest;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\GuestAccess\Exceptions\GuestAccessDeniedException;
use Modules\GuestAccess\Services\GuestAccessService;
use Shared\Audit\ActionType;
use Tests\TestCase;

/**
 * W6-T2 — sequential token validation and additional-code lockout.
 *
 * Every failure raises the same generic exception (GUEST_DENIED); the wrong
 * code path uses constant-time hash_equals; the 6th consecutive code failure
 * is throttled (5 fails → 15-minute lockout).
 */
class GuestGateTest extends TestCase
{
    use GuestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guestFixtureSetup();
    }

    public function test_valid_token_without_code_opens_session_and_records_access(): void
    {
        ['token' => $token] = $this->approveLink(code: null);

        $session = $this->service()->enter($token, null);

        $this->assertSame($this->containerId, $session->containerId);
        $this->assertSame(hash('sha256', $token), session('guest.token_hash'));
        $this->assertDatabaseHas('guest_access_log', ['guest_link_id' => $session->linkId]);
        $this->assertSame(1, DB::table('audit_log')
            ->where('action_type', ActionType::GUEST_ACCESS->value)
            ->where('entity_id', $session->linkId)
            ->count());
    }

    public function test_valid_token_with_correct_code_opens_session(): void
    {
        ['token' => $token] = $this->approveLink(code: 'WA-CODE-123');

        $session = $this->service()->enter($token, 'WA-CODE-123');

        $this->assertSame($this->containerId, $session->containerId);
    }

    public function test_unknown_token_denied_generically_without_audit(): void
    {
        try {
            $this->service()->enter(str_repeat('0', 64), null);
            $this->fail('Unknown token must be denied.');
        } catch (GuestAccessDeniedException $exception) {
            $this->assertSame('GUEST_DENIED', $exception->getMessage());
            $this->assertFalse($exception->isThrottled);
        }

        $this->assertSame(0, DB::table('guest_access_log')->count());
        $this->assertSame(0, DB::table('audit_log')
            ->where('action_type', ActionType::GUEST_ACCESS->value)
            ->count());
    }

    public function test_malformed_token_denied_generically(): void
    {
        $this->expectException(GuestAccessDeniedException::class);
        $this->service()->enter('not-a-token', null);
    }

    public function test_expired_token_denied_generically(): void
    {
        ['token' => $token, 'link_id' => $linkId] = $this->approveLink();
        DB::table('guest_link')->where('id', $linkId)->update([
            'tanggal_kadaluarsa' => now()->subMinute(),
        ]);

        $this->expectException(GuestAccessDeniedException::class);
        $this->service()->enter($token, null);
    }

    public function test_closed_container_denied_generically(): void
    {
        ['token' => $token] = $this->approveLink();
        DB::table('interview_container')->where('id', $this->containerId)->update(['status' => 'Ditutup']);

        $this->expectException(GuestAccessDeniedException::class);
        $this->service()->enter($token, null);
    }

    public function test_wrong_code_denied_and_fifth_failure_locks_out(): void
    {
        ['token' => $token] = $this->approveLink(code: 'correct-code');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $this->service()->enter($token, 'wrong-code-'.$attempt);
                $this->fail('Wrong code must be denied.');
            } catch (GuestAccessDeniedException $exception) {
                $this->assertSame('GUEST_DENIED', $exception->getMessage());
                $this->assertFalse($exception->isThrottled, "Attempt {$attempt} must not be throttled yet.");
            }
        }

        // 6th attempt — even with the correct code — is locked out for 15 min.
        try {
            $this->service()->enter($token, 'correct-code');
            $this->fail('Code lockout must reject the 6th attempt.');
        } catch (GuestAccessDeniedException $exception) {
            $this->assertSame('GUEST_DENIED', $exception->getMessage());
            $this->assertTrue($exception->isThrottled);
        }

        $this->assertSame(0, DB::table('guest_access_log')->count());
    }

    public function test_code_hash_is_compared_constant_time_and_raw_code_never_stored(): void
    {
        $this->approveLink(code: 'sensitive-code');

        $link = DB::table('guest_link')->where('interview_container_id', $this->containerId)->first();
        $this->assertSame(hash('sha256', 'sensitive-code'), $link->kode_tambahan_hash);
        $this->assertNotSame('sensitive-code', $link->kode_tambahan_hash);
    }

    public function test_current_session_revalidates_container_and_expiry(): void
    {
        ['token' => $token] = $this->approveLink();
        $session = $this->service()->enter($token, null);

        $this->assertSame($this->containerId, $this->service()->currentSession()->containerId);

        DB::table('guest_link')->where('id', $session->linkId)->update([
            'tanggal_kadaluarsa' => now()->subMinute(),
        ]);

        $this->expectException(GuestAccessDeniedException::class);
        $this->service()->currentSession();
    }

    public function test_no_raw_token_in_access_log_or_audit(): void
    {
        ['token' => $token, 'link_id' => $linkId] = $this->approveLink(code: null);
        $this->service()->enter($token, null);

        foreach (DB::table('guest_access_log')->get() as $row) {
            foreach ((array) $row as $value) {
                $this->assertFalse(is_string($value) && str_contains($value, $token));
            }
        }

        $audit = DB::table('audit_log')
            ->where('action_type', ActionType::GUEST_ACCESS->value)
            ->where('entity_id', $linkId)
            ->first();
        $this->assertNotNull($audit);
        $detail = is_string($audit->detail) ? json_decode($audit->detail, true) : $audit->detail;
        $this->assertIsArray($detail);
        $this->assertSame($linkId, (int) ($detail['token_id'] ?? 0));
        $this->assertSame($this->containerId, (int) ($detail['container_id'] ?? 0));
        $this->assertFalse(str_contains((string) $audit->detail, $token));
    }

    private function service(): GuestAccessService
    {
        return app(GuestAccessService::class);
    }
}
