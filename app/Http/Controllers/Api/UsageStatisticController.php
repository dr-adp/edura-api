<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUsageStatisticRequest;
use App\Http\Requests\UpdateUsageStatisticRequest;
use App\Models\UsageStatistic;
use App\Services\UsageStatisticsService;
use App\Support\InstitutionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageStatisticController extends Controller
{
    public function __construct(
        private readonly UsageStatisticsService $usageStatisticsService,
        private readonly InstitutionAccess $institutionAccess
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', UsageStatistic::class);

        $statistics = UsageStatistic::with(['institution', 'subscription'])
            ->when(
                $request->user()->hasRole('institution-admin'),
                fn ($query) => $query->where(
                    'institution_id',
                    $this->institutionAccess->institutionIdFor($request->user())
                )
            )
            ->when($request->filled('metric'), fn ($query) => $query->where('metric', $request->query('metric')))
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'Usage statistics fetched successfully.',
            'data' => $statistics,
        ]);
    }

    public function store(StoreUsageStatisticRequest $request): JsonResponse
    {
        $this->authorize('create', UsageStatistic::class);

        $statistic = $this->usageStatisticsService->record(
            $request->validated()
        );

        return response()->json([
            'message' => 'Usage statistic saved successfully.',
            'data' => $statistic,
        ], 201);
    }

    public function show(UsageStatistic $usageStatistic): JsonResponse
    {
        $this->authorize('view', $usageStatistic);

        return response()->json([
            'message' => 'Usage statistic fetched successfully.',
            'data' => $usageStatistic->load(['institution', 'subscription']),
        ]);
    }

    public function update(
        UpdateUsageStatisticRequest $request,
        UsageStatistic $usageStatistic
    ): JsonResponse {
        $this->authorize('update', $usageStatistic);

        $statistic = $this->usageStatisticsService->update(
            $usageStatistic,
            $request->validated()
        );

        return response()->json([
            'message' => 'Usage statistic updated successfully.',
            'data' => $statistic,
        ]);
    }

    public function destroy(UsageStatistic $usageStatistic): JsonResponse
    {
        $this->authorize('delete', $usageStatistic);

        $this->usageStatisticsService->delete($usageStatistic);

        return response()->json([
            'message' => 'Usage statistic deleted successfully.',
        ]);
    }
}
