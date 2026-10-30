<?php

namespace App\Http\Controllers;

use App\Actions\RecordUsage;
use App\Http\Requests\RecordUsageRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class UsageController extends Controller
{
    public function __invoke(RecordUsageRequest $request, RecordUsage $recordUsage): JsonResponse
    {
        $recorded = $recordUsage->handle($request->toData());

        return response()->json(
            ['status' => $recorded ? 'recorded' : 'duplicate'],
            $recorded ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }
}
