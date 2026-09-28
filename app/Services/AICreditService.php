<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\AICreditTransaction;
use App\Models\Institution;
use App\Models\Subscription;
use Illuminate\Database\UniqueConstraintViolationException;
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

    public function grantIncludedForSubscription(
        Subscription $subscription,
        array $data = []
    ): ?AICreditTransaction {
        $subscriptionId = (int) $subscription->getKey();

        try {
            return DB::transaction(function () use (
                $subscriptionId,
                $subscription,
                $data
            ): ?AICreditTransaction {
                $institution = Institution::query()
                    ->whereKey($subscription->institution_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $subscription = Subscription::query()
                    ->lockForUpdate()
                    ->findOrFail($subscriptionId);

                if ((int) $subscription->institution_id !== $institution->id) {
                    throw new DomainException(
                        'The grant institution must match the subscription institution.'
                    );
                }

                if (
                    isset($data['institution_id']) &&
                    (int) $data['institution_id'] !== (int) $subscription->institution_id
                ) {
                    throw new DomainException(
                        'The grant institution must match the subscription institution.'
                    );
                }

                $existing = $this->findSubscriptionIncludedGrant($subscription);

                if ($existing !== null) {
                    $claimExists = DB::table('subscription_ai_credit_grants')
                        ->where('subscription_id', $subscriptionId)
                        ->exists();

                    if (!$claimExists) {
                        DB::table('subscription_ai_credit_grants')->insert([
                            'subscription_id' => $subscriptionId,
                        ]);
                    }

                    return $existing->load([
                        'institution',
                        'subscription',
                        'createdBy',
                    ]);
                }

                if (!$subscription->isActive()) {
                    throw new DomainException(
                        'Subscription-included credits can only be granted to an active subscription.'
                    );
                }

                $subscription->loadMissing('subscriptionPlan');
                $credits = (float) (
                    $subscription->subscriptionPlan?->included_ai_credits ?? 0
                );

                if ($credits <= 0) {
                    return null;
                }

                if (
                    isset($data['credits']) &&
                    number_format((float) $data['credits'], 4, '.', '') !==
                        number_format($credits, 4, '.', '')
                ) {
                    throw new DomainException(
                        'Subscription-included grants must match the plan credit amount.'
                    );
                }

                DB::table('subscription_ai_credit_grants')->insert([
                    'subscription_id' => $subscriptionId,
                ]);

                return $this->recordLedgerTransaction(array_merge(
                    [
                        'description' => 'Included AI credits for subscription.',
                    ],
                    $data,
                    [
                        'institution_id' => $subscription->institution_id,
                        'subscription_id' => $subscriptionId,
                        'transaction_type' => 'grant',
                        'credits' => $credits,
                        'source' => 'subscription_included',
                    ]
                ));
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findSubscriptionIncludedGrant($subscription);

            if ($existing !== null) {
                return $existing->load([
                    'institution',
                    'subscription',
                    'createdBy',
                ]);
            }

            throw $exception;
        }
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
        if (
            ($data['transaction_type'] ?? null) === 'grant' &&
            strtolower(trim((string) ($data['source'] ?? ''))) ===
                'subscription_included'
        ) {
            $subscriptionId = (int) ($data['subscription_id'] ?? 0);
            $subscription = Subscription::query()->find($subscriptionId);

            if ($subscription === null) {
                throw new DomainException(
                    'Subscription-included grants require a valid subscription.'
                );
            }

            $transaction = $this->grantIncludedForSubscription(
                $subscription,
                $data
            );

            if ($transaction === null) {
                throw new DomainException(
                    'The subscription plan does not include positive AI credits.'
                );
            }

            return $transaction;
        }

        return $this->recordLedgerTransaction($data);
    }

    private function recordLedgerTransaction(array $data): AICreditTransaction
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

    private function findSubscriptionIncludedGrant(
        Subscription $subscription
    ): ?AICreditTransaction {
        return AICreditTransaction::query()
            ->forInstitution((int) $subscription->institution_id)
            ->where('subscription_id', $subscription->id)
            ->where('transaction_type', 'grant')
            ->where('source', 'subscription_included')
            ->oldest('id')
            ->lockForUpdate()
            ->first();
    }
}
