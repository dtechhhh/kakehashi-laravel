<?php

namespace Modules\Auth\Public;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Modules\Auth\StepUpAction;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Public Auth contract: step-up elevation gate (API_CONTRACTS §5).
 *
 * TTL 5 minutes, per-action + entity scope, single-use. Not password.confirm.
 * Failure throttle: 5 attempts / 15 minutes per user+IP.
 */
final class StepUpService
{
    public const TTL_SECONDS = 300;

    public const MAX_ATTEMPTS = 5;

    public const LOCKOUT_SECONDS = 900;

    private const SESSION_KEY = 'stepup.tokens';

    public function __construct(
        private readonly TwoFactorAuthenticationProvider $totp,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Re-auth password + TOTP and store a scoped elevation token (5 minutes).
     */
    public function elevate(
        string $password,
        string $code,
        string $action,
        string $entityType,
        int|string $entityId,
        ?string $ip = null,
    ): void {
        if (! StepUpAction::isValid($action)) {
            throw new HttpResponseException(response()->json([
                'message' => 'STEPUP_ACTION_INVALID',
            ], 422));
        }

        $user = Auth::user();
        if ($user === null) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        $throttleKey = $this->throttleKey((int) $user->getAuthIdentifier(), $ip);

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $this->recordAttempt($user, $action, $entityType, $entityId, 'fail', 'locked_out', $ip);

            throw new HttpResponseException(response()->json([
                'message' => 'STEPUP_LOCKED_OUT',
                'retry_after' => RateLimiter::availableIn($throttleKey),
            ], 429));
        }

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            $this->reject($user, $throttleKey, $action, $entityType, $entityId, 'code', 'two_factor_missing', $ip);
        }

        if (! Hash::check($password, $user->password)) {
            $this->reject($user, $throttleKey, $action, $entityType, $entityId, 'password', 'password_invalid', $ip);
        }

        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        if (! $this->totp->verify($secret, $code)) {
            $this->reject($user, $throttleKey, $action, $entityType, $entityId, 'code', 'totp_invalid', $ip);
        }

        RateLimiter::clear($throttleKey);
        $this->recordAttempt($user, $action, $entityType, $entityId, 'success', null, $ip);

        $key = $this->tokenKey($action, $entityType, $entityId);
        $tokens = session(self::SESSION_KEY, []);
        $tokens[$key] = now()->addSeconds(self::TTL_SECONDS)->getTimestamp();
        session([self::SESSION_KEY => $tokens]);

        session()->regenerate();
    }

    /**
     * Assert valid elevation for the action/entity; consume token (single-use).
     */
    public function require(string $action, string $entityType, int|string $entityId): void
    {
        if (! StepUpAction::isValid($action)) {
            throw new HttpResponseException(response()->json([
                'message' => 'STEPUP_ACTION_INVALID',
            ], 422));
        }

        $key = $this->tokenKey($action, $entityType, $entityId);
        $tokens = session(self::SESSION_KEY, []);
        $expiresAt = $tokens[$key] ?? null;

        if ($expiresAt === null || $expiresAt < now()->getTimestamp()) {
            unset($tokens[$key]);
            session([self::SESSION_KEY => $tokens]);

            throw new HttpResponseException(response()->json([
                'message' => 'STEPUP_REQUIRED',
            ], 403));
        }

        unset($tokens[$key]);
        session([self::SESSION_KEY => $tokens]);
    }

    public function hasValidElevation(string $action, string $entityType, int|string $entityId): bool
    {
        if (! StepUpAction::isValid($action)) {
            return false;
        }

        $key = $this->tokenKey($action, $entityType, $entityId);
        $expiresAt = session(self::SESSION_KEY, [])[$key] ?? null;

        return $expiresAt !== null && $expiresAt >= now()->getTimestamp();
    }

    private function reject(
        User $user,
        string $throttleKey,
        string $action,
        string $entityType,
        int|string $entityId,
        string $field,
        string $reason,
        ?string $ip,
    ): never {
        RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);
        $this->recordAttempt($user, $action, $entityType, $entityId, 'fail', $reason, $ip);

        throw new HttpResponseException(response()->json([
            'message' => 'STEPUP_FAILED',
            'errors' => [$field => ['STEPUP_FAILED']],
        ], 403));
    }

    private function recordAttempt(
        User $user,
        string $action,
        string $entityType,
        int|string $entityId,
        string $result,
        ?string $reason,
        ?string $ip,
    ): void {
        $detail = [
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'result' => $result,
        ];

        if ($reason !== null) {
            $detail['reason'] = $reason;
        }

        $this->audit->record(
            actionType: $result === 'success' ? ActionType::STEPUP_REAUTH : ActionType::STEPUP_FAILED,
            entityType: $entityType,
            entityId: (int) $entityId,
            detail: $detail,
            actorId: $user->getKey(),
            ip: $ip,
        );
    }

    private function throttleKey(int $userId, ?string $ip): string
    {
        return 'stepup:'.$userId.'|'.($ip ?? 'unknown');
    }

    private function tokenKey(string $action, string $entityType, int|string $entityId): string
    {
        return $action.'.'.$entityType.'.'.$entityId;
    }
}
