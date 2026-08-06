<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;
use Symfony\Component\HttpFoundation\Response;

final class LogoutController
{
    public function destroy(Request $request, AuditLogger $audit): Response
    {
        $user = $request->user();

        $audit->record(
            actionType: ActionType::LOGOUT,
            entityType: 'user',
            entityId: $user->getKey(),
            detail: ['user_id' => $user->getKey()],
            actorId: $user->getKey(),
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (! $request->expectsJson()) {
            return redirect()->route('login.form');
        }

        return response()->json(['message' => 'LOGOUT']);
    }
}
