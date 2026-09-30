<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpireSubscriptionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private SubscriptionPlan $plan;

    public function test_renewed_subscription_is_not_expired_using_stale_data(): void
    {
        $subscription = $this->createSubscription([
            'status' => Subscription::STATUS_ACTIVE,
            'trial_ends_at' => null,
            'current_period_starts_at' => now()->subYear(),
            'current_period_ends_at' => now()->subDays(8),
        ]);

        // Simulate the model selected by the command before renewal.
        $staleSubscription = Subscription::findOrFail(
            $subscription->id
        );

        // Simulate a renewal before the expiry operation runs.
        $subscription->update([
            'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addYear(),
        ]);

        $result = app(\App\Services\SubscriptionService::class)
            ->expireIfEligible($staleSubscription, 7);

        $this->assertNull($result);

        $subscription->refresh();

        $this->assertSame(
            Subscription::STATUS_ACTIVE,
            $subscription->status
        );

        $this->assertNull($subscription->expires_at);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'subscriptions.active_grace_period_days' => 7,
        ]);

        $this->institution = Institution::create([
            'name' => 'Expiry Test Institution',
            'code' => 'EXP-' . strtoupper(Str::random(8)),
        ]);

        $this->plan = SubscriptionPlan::create([
            'name' => 'Expiry Test Plan',
            'code' => 'EXP-PLAN-' . strtoupper(Str::random(8)),
            'price' => 1000,
            'billing_cycle' => 'yearly',
            'trial_days' => 14,
            'max_teachers' => 10,
            'max_students' => 100,
            'max_courses' => 20,
            'storage_limit_mb' => 1024,
            'included_ai_credits' => 100,
            'limits' => [
                'teachers' => 10,
                'students' => 100,
                'courses' => 20,
                'storage_mb' => 1024,
                'api_requests' => 1000,
                'ai_credits' => 100,
            ],
            'status' => 'active',
        ]);
    }

    private function createSubscription(array $attributes): Subscription
    {
        return Subscription::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'institution_id' => $this->institution->id,
            'subscription_plan_id' => $this->plan->id,
            'status' => Subscription::STATUS_TRIAL,
            'billing_cycle' => 'yearly',
            'starts_at' => now()->subMonth(),
        ], $attributes));
    }

    public function test_it_expires_a_trial_whose_end_date_has_passed(): void
    {
        $subscription = $this->createSubscription([
            'status' => Subscription::STATUS_TRIAL,
            'trial_ends_at' => now()->subMinute(),
        ]);

        Artisan::call('subscriptions:expire');

        $subscription->refresh();

        $this->assertSame(
            Subscription::STATUS_EXPIRED,
            $subscription->status
        );

        $this->assertNotNull($subscription->expires_at);
    }

    public function test_it_does_not_expire_a_trial_that_has_not_ended(): void
    {
        $subscription = $this->createSubscription([
            'status' => Subscription::STATUS_TRIAL,
            'trial_ends_at' => now()->addDay(),
        ]);

        Artisan::call('subscriptions:expire');

        $this->assertSame(
            Subscription::STATUS_TRIAL,
            $subscription->fresh()->status
        );
    }

    public function test_it_does_not_expire_an_active_subscription_during_grace_period(): void
    {
        $subscription = $this->createSubscription([
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_ends_at' => now()->subDays(3),
        ]);

        Artisan::call('subscriptions:expire');

        $this->assertSame(
            Subscription::STATUS_ACTIVE,
            $subscription->fresh()->status
        );
    }

    public function test_it_expires_an_active_subscription_after_grace_period(): void
    {
        $subscription = $this->createSubscription([
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_ends_at' => now()->subDays(8),
        ]);

        Artisan::call('subscriptions:expire');

        $this->assertSame(
            Subscription::STATUS_EXPIRED,
            $subscription->fresh()->status
        );
    }

    public function test_it_skips_subscriptions_without_expiry_dates(): void
    {
        $trial = $this->createSubscription([
            'status' => Subscription::STATUS_TRIAL,
            'trial_ends_at' => null,
        ]);

        $active = $this->createSubscription([
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_ends_at' => null,
        ]);

        Artisan::call('subscriptions:expire');

        $this->assertSame(
            Subscription::STATUS_TRIAL,
            $trial->fresh()->status
        );

        $this->assertSame(
            Subscription::STATUS_ACTIVE,
            $active->fresh()->status
        );
    }

    public function test_it_does_not_expire_suspended_subscriptions(): void
    {
        $subscription = $this->createSubscription([
            'status' => Subscription::STATUS_SUSPENDED,
            'current_period_ends_at' => now()->subDays(30),
        ]);

        Artisan::call('subscriptions:expire');

        $this->assertSame(
            Subscription::STATUS_SUSPENDED,
            $subscription->fresh()->status
        );
    }

    public function test_running_the_command_twice_does_not_expire_the_same_subscription_twice(): void
    {
        $subscription = $this->createSubscription([
            'status' => Subscription::STATUS_TRIAL,
            'trial_ends_at' => now()->subDay(),
        ]);

        Artisan::call('subscriptions:expire');

        $firstExpiryTime = $subscription->fresh()->expires_at;

        Artisan::call('subscriptions:expire');

        $subscription->refresh();

        $this->assertSame(
            Subscription::STATUS_EXPIRED,
            $subscription->status
        );

        $this->assertEquals(
            $firstExpiryTime,
            $subscription->expires_at
        );
    }
}
