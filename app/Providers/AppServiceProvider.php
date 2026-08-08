<?php

namespace App\Providers;

use App\Models\AICreditTransaction;
use App\Models\Feature;
use App\Models\InstitutionSetting;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\UsageStatistic;
use App\Policies\AICreditTransactionPolicy;
use App\Policies\FeaturePolicy;
use App\Policies\InstitutionSettingPolicy;
use App\Policies\PlanFeaturePolicy;
use App\Policies\SubscriptionPlanPolicy;
use App\Policies\SubscriptionPolicy;
use App\Policies\UsageStatisticPolicy;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(SubscriptionPlan::class, SubscriptionPlanPolicy::class);
        Gate::policy(Subscription::class, SubscriptionPolicy::class);
        Gate::policy(Feature::class, FeaturePolicy::class);
        Gate::policy(PlanFeature::class, PlanFeaturePolicy::class);
        Gate::policy(InstitutionSetting::class, InstitutionSettingPolicy::class);
        Gate::policy(AICreditTransaction::class, AICreditTransactionPolicy::class);
        Gate::policy(UsageStatistic::class, UsageStatisticPolicy::class);

        // Rate limiting: Protect auth endpoints from brute force
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // General API rate limit
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
