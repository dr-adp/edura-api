<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Institution;
use App\Models\UsageStatistic;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class UsageStatisticsService
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService
    ) {
    }

    public function record(array $data): UsageStatistic
    {
        return DB::transaction(function () use ($data) {
            $subscription = $this->subscriptionService
                ->currentForInstitution((int) $data['institution_id']);

            $statistic = UsageStatistic::updateOrCreate(
                [
                    'institution_id' => $data['institution_id'],
                    'metric' => $data['metric'],
                    'period_start' => $data['period_start'],
                    'period_end' => $data['period_end'],
                ],
                [
                    'subscription_id' => $data['subscription_id']
                        ?? $subscription?->id,
                    'used_value' => $data['used_value'] ?? 0,
                    'limit_value' => $data['limit_value']
                        ?? $this->limitForInstitution(
                            (int) $data['institution_id'],
                            $data['metric']
                        ),
                    'metadata' => $data['metadata'] ?? null,
                ]
            );

            return $statistic->load(['institution', 'subscription']);
        });
    }

    public function update(UsageStatistic $usageStatistic, array $data): UsageStatistic
    {
        return DB::transaction(function () use ($usageStatistic, $data) {
            $usageStatistic->update($data);

            return $usageStatistic->fresh()->load(['institution', 'subscription']);
        });
    }

    public function delete(UsageStatistic $usageStatistic): bool
    {
        return DB::transaction(
            fn (): bool => (bool) $usageStatistic->delete()
        );
    }

    public function increment(
        Institution|int $institution,
        string $metric,
        float $amount = 1,
        ?Carbon $periodStart = null,
        ?Carbon $periodEnd = null
    ): UsageStatistic {
        $institutionId = $institution instanceof Institution
            ? $institution->id
            : $institution;
        $periodStart ??= now()->startOfMonth();
        $periodEnd ??= now()->endOfMonth();

        return DB::transaction(function () use (
            $institutionId,
            $metric,
            $amount,
            $periodStart,
            $periodEnd
        ) {
            $subscription = $this->subscriptionService
                ->currentForInstitution((int) $institutionId);

            $statistic = UsageStatistic::query()
                ->where('institution_id', $institutionId)
                ->where('metric', $metric)
                ->whereDate('period_start', $periodStart->toDateString())
                ->whereDate('period_end', $periodEnd->toDateString())
                ->lockForUpdate()
                ->first();

            if (!$statistic) {
                $statistic = new UsageStatistic([
                    'institution_id' => $institutionId,
                    'metric' => $metric,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'used_value' => 0,
                    'limit_value' => $this->limitForInstitution(
                        (int) $institutionId,
                        $metric
                    ),
                    'subscription_id' => $subscription?->id,
                ]);
            }

            $statistic->used_value = (float) $statistic->used_value + $amount;

            if (
                $statistic->limit_value !== null &&
                (float) $statistic->used_value > (float) $statistic->limit_value
            ) {
                throw new DomainException(
                    "Usage limit exceeded for metric [{$metric}]."
                );
            }

            $statistic->save();

            return $statistic->fresh()->load(['institution', 'subscription']);
        });
    }

    public function assertWithinLimit(
        Institution|int $institution,
        string $metric,
        float $nextAmount = 1
    ): void {
        $institutionId = $institution instanceof Institution
            ? $institution->id
            : $institution;
        $periodStart = now()->startOfMonth()->toDateString();
        $periodEnd = now()->endOfMonth()->toDateString();
        $limit = $this->limitForInstitution((int) $institutionId, $metric);

        if ($limit === null) {
            return;
        }

        $used = UsageStatistic::query()
            ->where('institution_id', $institutionId)
            ->where('metric', $metric)
            ->whereDate('period_start', $periodStart)
            ->whereDate('period_end', $periodEnd)
            ->value('used_value') ?? 0;

        if (((float) $used + $nextAmount) > $limit) {
            throw new DomainException(
                "Usage limit exceeded for metric [{$metric}]."
            );
        }
    }

    public function limitForInstitution(int $institutionId, string $metric): ?float
    {
        $plan = $this->subscriptionService
            ->currentPlanForInstitution($institutionId);

        return $plan?->limit($metric);
    }
}
