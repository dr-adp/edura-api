<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExpireSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description =
    'Expire trials and active subscriptions that have passed their expiry dates';

    public function handle(SubscriptionService $subscriptionService): int
    {
        $now = now();
        $gracePeriodDays = max(
            0,
            (int) config('subscriptions.active_grace_period_days', 7)
        );

        $expiredCount = 0;
        $failedCount = 0;

        Subscription::query()
            ->where(function ($query) use ($now, $gracePeriodDays) {
                $query
                    ->where(function ($trialQuery) use ($now) {
                        $trialQuery
                            ->where('status', Subscription::STATUS_TRIAL)
                            ->whereNotNull('trial_ends_at')
                            ->where('trial_ends_at', '<=', $now);
                    })
                    ->orWhere(function ($activeQuery) use (
                        $now,
                        $gracePeriodDays
                    ) {
                        $activeQuery
                            ->where('status', Subscription::STATUS_ACTIVE)
                            ->whereNotNull('current_period_ends_at')
                            ->where(
                                'current_period_ends_at',
                                '<=',
                                $now->copy()->subDays($gracePeriodDays)
                            );
                    });
            })
            ->chunkById(100, function ($subscriptions) use (
                $subscriptionService,
                $gracePeriodDays,
                &$expiredCount,
                &$failedCount
            ) {
                foreach ($subscriptions as $subscription) {
                    try {
                        $expiredSubscription = $subscriptionService->expireIfEligible(
                            $subscription,
                            $gracePeriodDays
                        );

                        if ($expiredSubscription !== null) {
                            $expiredCount++;
                        }
                    } catch (Throwable $exception) {
                        $failedCount++;

                        Log::error(
                            'Automated subscription expiry failed.',
                            [
                                'subscription_id' => $subscription->id,
                                'exception' => $exception->getMessage(),
                            ]
                        );
                    }
                }
            });

        $this->info("Subscriptions expired: {$expiredCount}");

        if ($failedCount > 0) {
            $this->warn("Subscriptions that failed: {$failedCount}");
        }

        return $failedCount > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
