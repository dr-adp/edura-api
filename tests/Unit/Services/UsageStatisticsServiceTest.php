<?php

namespace Tests\Unit\Services;

use App\Exceptions\DomainException;
use App\Models\Institution;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\UsageStatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UsageStatisticsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_increment_enforces_database_configured_limit(): void
    {
        $institution = Institution::create([
            'name' => 'Usage Academy',
            'code' => 'USAGE-A',
        ]);
        $plan = SubscriptionPlan::create([
            'name' => 'Starter',
            'code' => 'STARTER',
            'price' => 100,
            'billing_cycle' => 'monthly',
            'max_teachers' => 2,
            'max_students' => 5,
            'max_courses' => 1,
            'storage_limit_mb' => 100,
            'limits' => [
                'students' => 2,
            ],
            'status' => 'active',
        ]);
        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'institution_id' => $institution->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
        ]);

        $service = app(UsageStatisticsService::class);

        $service->increment($institution, 'students', 1);
        $service->increment($institution, 'students', 1);

        $this->expectException(DomainException::class);

        $service->increment($institution, 'students', 1);
    }
}
