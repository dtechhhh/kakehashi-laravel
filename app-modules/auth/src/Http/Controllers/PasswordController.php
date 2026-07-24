<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Rules\PasswordPolicy;

final class PasswordController
{
    public function update(Request $request): JsonResponse
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

        $user->forceFill([
            'password' => $data['password'],
            'must_change_password' => false,
        ])->save();

        $request->session()->regenerate();

        return response()->json(['message' => 'PASSWORD_CHANGED']);
    }
}
