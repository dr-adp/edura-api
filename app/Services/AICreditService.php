<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\AICreditTransaction;
use App\Models\Institution;
use Illuminate\Support\Facades\DB;

class AICreditService
{
    public function __construct(
        private readonly AuditLogService $auditLogService
    ) {}

    public function balance(Institution|int $institution): float
    {
        $institutionId = $institution instanceof Institution
            ? $institution->id
            : $institution;

        $lastTransaction = AICreditTransaction::query()
            ->forInstitution((int) $institutionId)
            ->latest('id')
            ->first();

        return $lastTransaction
            ? (float) $lastTransaction->balance_after
            : 0.0;
    }

    public function grant(array $data): AICreditTransaction
    {
        return $this->record(array_merge($data, [
            'transaction_type' => 'grant',
        ]));
    }

    public function consume(array $data): AICreditTransaction
    {
        return $this->record(array_merge($data, [
            'transaction_type' => 'consume',
        ]));
    }

    public function refund(array $data): AICreditTransaction
    {
        return $this->record(array_merge($data, [
            'transaction_type' => 'refund',
        ]));
    }

    public function record(array $data): AICreditTransaction
    {
        return DB::transaction(function () use ($data) {
            $institutionId = (int) $data['institution_id'];

            $previous = AICreditTransaction::query()
                ->forInstitution($institutionId)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $previousBalance = $previous
                ? (float) $previous->balance_after
                : 0.0;

            $change = $this->signedCreditChange(
                $data['transaction_type'],
                (float) $data['credits']
            );

            $newBalance = $previousBalance + $change;

            if ($newBalance < 0) {
                throw new DomainException(
                    'Insufficient AI credits for this transaction.',
                    [
                        'balance' => $previousBalance,
                        'required' => abs($change),
                    ]
                );
            }

            $transaction = AICreditTransaction::create(array_merge(
                $data,
                [
                    'credits' => $change,
                    'balance_after' => $newBalance,
                ]
            ))->load([
                'institution',
                'subscription',
                'createdBy',
            ]);

            $action = match ($data['transaction_type']) {
                'grant' => 'ai_credits_granted',
                'consume' => 'ai_credits_consumed',
                'refund' => 'ai_credits_refunded',
                default => 'ai_credits_adjusted',
            };

            $description = match ($data['transaction_type']) {
                'grant' => 'AI credits granted.',
                'consume' => 'AI credits consumed.',
                'refund' => 'AI credits refunded.',
                default => 'AI credit balance adjusted.',
            };

            $this->auditLogService->recordCustom(
                action: $action,
                description: $description,
                auditable: $transaction,
                institutionId: $institutionId,
                module: 'AICredit',
                metadata: [
                    'transaction_type' => $transaction->transaction_type,
                    'credits' => $transaction->credits,
                    'balance_after' => $transaction->balance_after,
                    'subscription_id' => $transaction->subscription_id,
                ]
            );

            return $transaction;
        });
    }

    private function signedCreditChange(
        string $type,
        float $credits
    ): float {
        return match ($type) {
            'consume', 'expiry' => -abs($credits),
            'adjustment' => $credits,
            default => abs($credits),
        };
    }
}
