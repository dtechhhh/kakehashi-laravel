<?php

namespace Shared\Audit;

use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Single write path for audit_log (ARCH D4, API_CONTRACTS §6.1).
 * INSERT only; actor_role_snapshot taken at event time; detail is PII-minimized.
 */
final class AuditLogger
{
    /** Keys never stored in detail JSONB (secrets / raw credentials). */
    private const FORBIDDEN_DETAIL_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'temporary_password',
        'token',
        'plain_token',
        'raw_token',
        'secret',
        'totp',
        'totp_code',
        'otp',
        'recovery_code',
        'recovery_codes',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'email',
        'raw_email',
        'email_input',
    ];

    /**
     * @param  array<string, mixed>|null  $detail
     */
    public function record(
        ActionType $actionType,
        string $entityType,
        ?int $entityId = null,
        ?array $detail = null,
        ?int $actorId = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): AuditLog {
        $detail = $detail === null ? null : $this->sanitizeDetail($detail, $actionType);

        return AuditLog::query()->create([
            'actor_id' => $actorId,
            'actor_role_snapshot' => $this->snapshotRoles($actorId),
            'action_type' => $actionType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'detail' => $detail,
            'ip' => $ip,
            'user_agent' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * MODULE_AUTH: anonymous fail uses masked email or HMAC fingerprint — never raw input.
     */
    public function fingerprintEmail(string $email): string
    {
        $normalized = Str::lower(trim($email));

        return 'hmac:'.hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    /**
     * Optional display-safe mask (local part truncated). Prefer fingerprintEmail for storage.
     */
    public function maskEmail(string $email): string
    {
        $normalized = Str::lower(trim($email));
        $at = strrpos($normalized, '@');

        if ($at === false || $at === 0) {
            return '***';
        }

        $local = substr($normalized, 0, $at);
        $domain = substr($normalized, $at + 1);
        $head = $local === '' ? '*' : $local[0];

        return $head.'***@'.$domain;
    }

    private function snapshotRoles(?int $actorId): ?string
    {
        if ($actorId === null) {
            return null;
        }

        $user = User::query()->find($actorId);

        if ($user === null) {
            return null;
        }

        $roles = $user->getRoleNames()->sort()->values()->all();

        if ($roles === []) {
            return null;
        }

        return implode(', ', $roles);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function sanitizeDetail(array $detail, ActionType $actionType): array
    {
        $clean = [];

        foreach ($detail as $key => $value) {
            $keyStr = (string) $key;

            if ($this->isForbiddenKey($keyStr)) {
                throw new InvalidArgumentException(
                    "audit detail must not contain secret/PII key [{$keyStr}]"
                );
            }

            // MODULE_AUTH: IP only on column `ip` for Auth events (not Guest Lampiran A).
            if ($this->isAuthAction($actionType) && strcasecmp($keyStr, 'ip') === 0) {
                throw new InvalidArgumentException(
                    'auth audit detail must not contain ip; use the ip column'
                );
            }

            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $clean[$keyStr] = $this->sanitizeDetail($value, $actionType);

                continue;
            }

            if (is_string($value) && $this->isRawEmailString($value)) {
                throw new InvalidArgumentException(
                    'audit detail must not contain raw email; use fingerprintEmail() or user_id'
                );
            }

            $clean[$keyStr] = $value;
        }

        return $clean;
    }

    private function isAuthAction(ActionType $actionType): bool
    {
        return match ($actionType) {
            ActionType::LOGIN_SUCCESS,
            ActionType::LOGIN_FAILED,
            ActionType::LOGIN_LOCKED_OUT,
            ActionType::LOGOUT,
            ActionType::TWOFA_SETUP,
            ActionType::TWOFA_VERIFIED,
            ActionType::TWOFA_FAILED,
            ActionType::TWOFA_RECOVERY_USED,
            ActionType::PASSWORD_CHANGED,
            ActionType::STEPUP_REAUTH,
            ActionType::STEPUP_FAILED => true,
            default => false,
        };
    }

    private function isForbiddenKey(string $key): bool
    {
        $normalized = Str::lower($key);

        if (in_array($normalized, self::FORBIDDEN_DETAIL_KEYS, true)) {
            return true;
        }

        // Secrets only — allow opaque ids (token_id) and fingerprints (email_masked_or_fingerprint).
        if (preg_match('/(^|_)(password|secret|totp|otp|recovery_codes?|two_factor)(_|$)/', $normalized) === 1) {
            return true;
        }

        // Bare token / *_token secret material; token_id stays allowed.
        if ($normalized === 'token' || (str_ends_with($normalized, '_token') && ! str_ends_with($normalized, '_token_id'))) {
            return true;
        }

        return false;
    }

    /**
     * True when $value is a raw email address (any key). Fingerprint/mask formats are allowlisted.
     */
    private function isRawEmailString(string $value): bool
    {
        if ($this->isAllowedEmailSubstitute($value)) {
            return false;
        }

        return preg_match('/[^\s@]+@[^\s@]+/', $value) === 1;
    }

    /**
     * Strict substitutes only:
     * - fingerprint: hmac: + 64 lowercase hex (sha256)
     *
     * - mask: single local char + ***@ + domain (output of maskEmail)
     */
    private function isAllowedEmailSubstitute(string $value): bool
    {
        if (preg_match('/^hmac:[a-f0-9]{64}$/', $value) === 1) {
            return true;
        }

        // maskEmail(): "{first}***@{domain}" — not a valid raw mailbox local-part form.
        return preg_match('/^.\*\*\*@[^\s@]+$/', $value) === 1;
    }
}
