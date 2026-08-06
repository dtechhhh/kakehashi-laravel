<?php

namespace Modules\GuestAccess\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\GuestAccess\Exceptions\GuestAccessDeniedException;
use Modules\GuestAccess\GuestSession;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;

/**
 * W6-T2/T3 — token gate for the Guest surface.
 *
 * Sequential validation: token exists (hash lookup, never raw) → not expired →
 * container still Aktif → optional additional code (constant-time, lockout).
 * Failures throw one generic exception; rate-limit failures carry isThrottled
 * so the route can answer 429 with the same neutral page.
 *
 * Raw tokens and codes are never logged or persisted; failed attempts go to
 * the application security log, never to audit_log (Lampiran A has no enum).
 */
final class GuestAccessService
{
    private const INVALID_MAX_PER_MINUTE = 10;

    private const VALID_MAX_PER_MINUTE = 60;

    private const CODE_MAX_ATTEMPTS = 5;

    private const CODE_LOCK_SECONDS = 900;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Validate the raw token (+ optional code), open the read-only guest
     * session, and record GUEST_ACCESS + guest_access_log.
     *
     * @throws GuestAccessDeniedException
     */
    public function enter(string $rawToken, ?string $code): GuestSession
    {
        $ip = $this->clientIp();
        $hash = hash('sha256', $rawToken);

        $this->assertNotThrottled($this->invalidKey($ip), self::INVALID_MAX_PER_MINUTE);

        $link = $this->findLink($rawToken, $hash);
        if ($link === null) {
            RateLimiter::hit($this->invalidKey($ip), 60);
            $this->securityLog('invalid_token', $ip);

            throw new GuestAccessDeniedException;
        }

        if ($this->expired($link)) {
            RateLimiter::hit($this->invalidKey($ip), 60);
            $this->securityLog('expired_token', $ip, (int) $link->id);

            throw new GuestAccessDeniedException;
        }

        $container = DB::table('interview_container')->where('id', $link->interview_container_id)->first();
        if ($container === null || $container->status !== 'Aktif') {
            $this->securityLog('container_closed', $ip, (int) $link->id);

            throw new GuestAccessDeniedException;
        }

        if ($link->kode_tambahan_hash !== null) {
            $this->assertCode((int) $link->id, (string) $link->token_hash, (string) $link->kode_tambahan_hash, $code, $ip);
        }

        $this->assertNotThrottled($this->validKey($hash), self::VALID_MAX_PER_MINUTE);
        RateLimiter::hit($this->validKey($hash), 60);

        $this->recordAccess((int) $link->id, (int) $link->interview_container_id, $ip);

        session([
            'guest.link_id' => (int) $link->id,
            'guest.container_id' => (int) $link->interview_container_id,
            'guest.token_hash' => $hash,
        ]);

        return new GuestSession((int) $link->id, (int) $link->interview_container_id, $hash);
    }

    /**
     * Passive UX peek for the gate page: true only when a valid, active,
     * non-expired link for this token carries an additional code. Failure
     * paths still render the same generic denied page via enter().
     */
    public function requiresCode(string $rawToken): bool
    {
        if (preg_match('/^[0-9a-f]{64}$/', $rawToken) !== 1) {
            return false;
        }

        $link = DB::table('guest_link')
            ->where('token_hash', hash('sha256', $rawToken))
            ->where('status_link', 'Aktif')
            ->first();

        if ($link === null || $this->expired($link) || $link->kode_tambahan_hash === null) {
            return false;
        }

        $container = DB::table('interview_container')->where('id', $link->interview_container_id)->first();

        return $container !== null && $container->status === 'Aktif';
    }

    /**
     * Revalidate the open session before every guest render (race: link may
     * expire or the container may close between requests). Also consumes the
     * per-token valid budget so an office browsing counts against 60/min.
     *
     * @throws GuestAccessDeniedException
     */
    public function currentSession(): GuestSession
    {
        $linkId = session('guest.link_id');
        $containerId = session('guest.container_id');
        $tokenHash = session('guest.token_hash');

        if (! is_int($linkId) || ! is_int($containerId) || ! is_string($tokenHash) || $tokenHash === '') {
            throw new GuestAccessDeniedException;
        }

        $this->assertNotThrottled($this->validKey($tokenHash), self::VALID_MAX_PER_MINUTE);
        RateLimiter::hit($this->validKey($tokenHash), 60);

        $link = DB::table('guest_link')->where('id', $linkId)->where('token_hash', $tokenHash)->first();
        if ($link === null || $this->expired($link)) {
            throw new GuestAccessDeniedException;
        }

        $container = DB::table('interview_container')->where('id', $link->interview_container_id)->first();
        if ($container === null
            || $container->status !== 'Aktif'
            || (int) $container->id !== $containerId
        ) {
            throw new GuestAccessDeniedException;
        }

        return new GuestSession((int) $link->id, (int) $link->interview_container_id, $tokenHash);
    }

    private function findLink(string $rawToken, string $hash): ?object
    {
        // 256-bit random token (64 lowercase hex). Anything else is invalid.
        if (preg_match('/^[0-9a-f]{64}$/', $rawToken) !== 1) {
            return null;
        }

        return DB::table('guest_link')
            ->where('token_hash', $hash)
            ->where('status_link', 'Aktif')
            ->first();
    }

    private function expired(object $link): bool
    {
        return now()->greaterThanOrEqualTo(Carbon::parse($link->tanggal_kadaluarsa));
    }

    private function assertCode(int $linkId, string $tokenHash, string $storedHash, ?string $code, string $ip): void
    {
        $key = $this->codeKey($tokenHash, $ip);
        $this->assertNotThrottled($key, self::CODE_MAX_ATTEMPTS);

        $submitted = hash('sha256', (string) $code);
        if (! hash_equals($storedHash, $submitted)) {
            RateLimiter::hit($key, self::CODE_LOCK_SECONDS);
            $this->securityLog('code_failed', $ip, $linkId);

            throw new GuestAccessDeniedException;
        }
    }

    private function assertNotThrottled(string $key, int $maxAttempts): void
    {
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw new GuestAccessDeniedException(isThrottled: true);
        }
    }

    private function recordAccess(int $linkId, int $containerId, string $ip): void
    {
        DB::table('guest_access_log')->insert([
            'guest_link_id' => $linkId,
            'accessed_at' => now(),
            'ip' => $ip === '' ? null : $ip,
            'user_agent' => request()->userAgent() ?: null,
        ]);

        $this->audit->record(
            actionType: ActionType::GUEST_ACCESS,
            entityType: 'guest_link',
            entityId: $linkId,
            detail: [
                'token_id' => $linkId,
                'ip' => $ip,
                'container_id' => $containerId,
            ],
            ip: $ip === '' ? null : $ip,
        );
    }

    private function securityLog(string $reason, string $ip, ?int $linkId = null): void
    {
        Log::channel('security')->warning('guest_access_denied', [
            'guest_link_id' => $linkId,
            'reason' => $reason,
            'ip' => $ip,
        ]);
    }

    private function clientIp(): string
    {
        $ip = request()->ip();

        return is_string($ip) ? $ip : '';
    }

    private function invalidKey(string $ip): string
    {
        return 'guest:invalid:'.$ip;
    }

    private function validKey(string $tokenHash): string
    {
        return 'guest:valid:'.$tokenHash;
    }

    private function codeKey(string $tokenHash, string $ip): string
    {
        return 'guest:code:'.$tokenHash.':'.$ip;
    }
}
