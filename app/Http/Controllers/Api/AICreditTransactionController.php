<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAICreditTransactionRequest;
use App\Models\AICreditTransaction;
use App\Services\AICreditService;
use App\Support\InstitutionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AICreditTransactionController extends Controller
{
    public function __construct(
        private readonly AICreditService $aiCreditService,
        private readonly InstitutionAccess $institutionAccess
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AICreditTransaction::class);

        $transactions = AICreditTransaction::with(['institution', 'subscription', 'createdBy'])
            ->when(
                $request->user()->hasRole('institution-admin'),
                fn ($query) => $query->where(
                    'institution_id',
                    $this->institutionAccess->institutionIdFor($request->user())
                )
            )
            ->latest()
            ->paginate(20);

        return response()->json([
            'message' => 'AI credit transactions fetched successfully.',
            'data' => $transactions,
        ]);
    }

    public function store(StoreAICreditTransactionRequest $request): JsonResponse
    {
        $this->authorize('create', AICreditTransaction::class);

        try {
            $transaction = $this->aiCreditService->record(array_merge(
                $request->validated(),
                ['created_by_id' => $request->user()->id]
            ));
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->context(),
            ], 422);
        }

        return response()->json([
            'message' => 'AI credit transaction recorded successfully.',
            'data' => $transaction,
        ], 201);
    }

    public function show(AICreditTransaction $aiCreditTransaction): JsonResponse
    {
        $this->authorize('view', $aiCreditTransaction);

        return response()->json([
            'message' => 'AI credit transaction fetched successfully.',
            'data' => $aiCreditTransaction->load(['institution', 'subscription', 'createdBy']),
        ]);
    }
}
