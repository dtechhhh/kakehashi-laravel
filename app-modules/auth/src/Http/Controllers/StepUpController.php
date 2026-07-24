<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Public\StepUpService;
use Modules\Auth\StepUpAction;

final class StepUpController
{
    public function store(Request $request, StepUpService $stepUp): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string'],
            'action' => ['required', 'string', 'in:'.implode(',', StepUpAction::all())],
            'entity_type' => ['required', 'string', 'max:100'],
            'entity_id' => ['required', 'integer', 'min:1'],
        ]);

        $stepUp->elevate(
            $data['password'],
            $data['code'],
            $data['action'],
            $data['entity_type'],
            $data['entity_id'],
            $request->ip(),
        );

        return response()->json([
            'message' => 'STEPUP_OK',
            'ttl_seconds' => StepUpService::TTL_SECONDS,
        ]);
    }
}
