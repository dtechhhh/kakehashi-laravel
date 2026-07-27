<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Fortify;
use Modules\Auth\Rbac;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;

final class TwoFactorEnrollmentController
{
    public function enable(Request $request, EnableTwoFactorAuthentication $enable): JsonResponse
    {
        $user = $request->user();

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return response()->json(['message' => 'TWOFA_ALREADY_ENABLED'], 422);
        }

        // Pending enrollment: re-show existing secret/QR without rotating.
        if (empty($user->two_factor_secret)) {
            $enable($user, force: false);
            $user->refresh();
        }

        return response()->json([
            'message' => 'TWOFA_ENABLED',
            'otpauth_url' => $user->twoFactorQrCodeUrl(),
            // Secret only while enrollment is pending; never logged.
            'secret' => Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
            'confirmed' => false,
        ]);
    }

    public function confirm(
        Request $request,
        ConfirmTwoFactorAuthentication $confirm,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        try {
            DB::transaction(function () use ($audit, $confirm, $request, $user, $data): void {
                $confirm($user, $data['code']);

                $audit->record(
                    actionType: ActionType::TWOFA_SETUP,
                    entityType: 'user',
                    entityId: $user->getKey(),
                    detail: ['regenerate' => false],
                    actorId: $user->getKey(),
                    ip: $request->ip(),
                    userAgent: $request->userAgent(),
                );
            });
        } catch (ValidationException) {
            $audit->record(
                actionType: ActionType::TWOFA_FAILED,
                entityType: 'user',
                entityId: $user->getKey(),
                detail: [
                    'user_id' => $user->getKey(),
                    'reason' => 'enrollment_confirmation',
                ],
                actorId: $user->getKey(),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return response()->json([
                'message' => 'TWOFA_FAILED',
                'errors' => ['code' => ['TWOFA_FAILED']],
            ], 422);
        }

        $user->refresh();

        return response()->json([
            'message' => 'TWOFA_CONFIRMED',
            'recovery_codes' => $user->recoveryCodes(),
        ]);
    }

    public function disable(Request $request, DisableTwoFactorAuthentication $disable): JsonResponse
    {
        $user = $request->user();

        if ($user->hasAnyRole(Rbac::TWO_FACTOR_REQUIRED_ROLES)) {
            return response()->json([
                'message' => 'TWOFA_DISABLE_FORBIDDEN',
            ], 403);
        }

        $disable($user);

        return response()->json(['message' => 'TWOFA_DISABLED']);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return response()->json(['message' => 'TWOFA_NOT_ENABLED'], 422);
        }

        return response()->json([
            'recovery_codes' => $user->recoveryCodes(),
        ]);
    }

    public function regenerateRecoveryCodes(
        Request $request,
        GenerateNewRecoveryCodes $generate,
        AuditLogger $audit,
    ): JsonResponse {
        $user = $request->user();

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return response()->json(['message' => 'TWOFA_NOT_ENABLED'], 422);
        }

        DB::transaction(function () use ($audit, $generate, $request, $user): void {
            $generate($user);

            $audit->record(
                actionType: ActionType::TWOFA_SETUP,
                entityType: 'user',
                entityId: $user->getKey(),
                detail: ['regenerate' => true],
                actorId: $user->getKey(),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );
        });

        $user->refresh();

        return response()->json([
            'message' => 'TWOFA_RECOVERY_REGENERATED',
            'recovery_codes' => $user->recoveryCodes(),
        ]);
    }

    public function qrCode(Request $request): JsonResponse
    {
        $user = $request->user();

        if (empty($user->two_factor_secret) || $user->two_factor_confirmed_at !== null) {
            return response()->json(['message' => 'TWOFA_NOT_PENDING'], 422);
        }

        return response()->json([
            'svg' => $user->twoFactorQrCodeSvg(),
            'url' => $user->twoFactorQrCodeUrl(),
        ]);
    }

    public function secretKey(Request $request): JsonResponse
    {
        $user = $request->user();

        if (empty($user->two_factor_secret) || $user->two_factor_confirmed_at !== null) {
            return response()->json(['message' => 'TWOFA_NOT_PENDING'], 422);
        }

        return response()->json([
            'secret' => Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
        ]);
    }
}
