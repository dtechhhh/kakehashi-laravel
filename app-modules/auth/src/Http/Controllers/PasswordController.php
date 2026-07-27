<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Rules\PasswordPolicy;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;

final class PasswordController
{
    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', new PasswordPolicy],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'message' => 'PWD_CURRENT_INVALID',
                'errors' => ['current_password' => ['PWD_CURRENT_INVALID']],
            ], 422);
        }

        $forced = (bool) $user->must_change_password;

        DB::transaction(function () use ($audit, $data, $forced, $request, $user): void {
            $user->forceFill([
                'password' => $data['password'],
                'must_change_password' => false,
            ])->save();

            $audit->record(
                actionType: ActionType::PASSWORD_CHANGED,
                entityType: 'user',
                entityId: $user->getKey(),
                detail: [
                    'user_id' => $user->getKey(),
                    'forced' => $forced,
                ],
                actorId: $user->getKey(),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );
        });

        $request->session()->regenerate();

        return response()->json(['message' => 'PASSWORD_CHANGED']);
    }
}
