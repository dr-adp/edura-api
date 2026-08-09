<?php

namespace Tests\Unit\Services;

use App\Models\Course;
use App\Models\Institution;
use App\Models\StudentProfile;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\UsageReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UsageReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_counts_only_billable_resources(): void
    {
        $institution = Institution::create([
            'name' => 'Reconciliation Academy',
            'code' => 'RECON-001',
        ]);

        $plan = $this->createPlan($institution);

        $this->createSubscription($institution, $plan);

        $this->createStudent($institution, 'active');
        $this->createStudent($institution, 'active');
        $this->createStudent($institution, 'inactive');

        $this->createTeacher($institution, 'active');
        $this->createTeacher($institution, 'inactive');

        $this->createCourse($institution, 'draft');
        $this->createCourse($institution, 'published');
        $this->createCourse($institution, 'archived');

        $service = app(UsageReconciliationService::class);

        $result = $service->reconcileInstitution($institution);

        $this->assertSame(2, (int) $result['students']->used_value);
        $this->assertSame(1, (int) $result['teachers']->used_value);
        $this->assertSame(2, (int) $result['courses']->used_value);

        $this->assertDatabaseHas('usage_statistics', [
            'institution_id' => $institution->id,
            'metric' => 'students',
            'used_value' => 2,
        ]);

        $this->assertDatabaseHas('usage_statistics', [
            'institution_id' => $institution->id,
            'metric' => 'teachers',
            'used_value' => 1,
        ]);

        $this->assertDatabaseHas('usage_statistics', [
            'institution_id' => $institution->id,
            'metric' => 'courses',
            'used_value' => 2,
        ]);
    }

    public function test_reconciliation_is_idempotent(): void
    {
        $institution = Institution::create([
            'name' => 'Idempotent Academy',
            'code' => 'RECON-002',
        ]);

        $plan = $this->createPlan($institution);

        $this->createSubscription($institution, $plan);

        $this->createStudent($institution, 'active');
        $this->createStudent($institution, 'active');

        $service = app(UsageReconciliationService::class);

        $service->reconcileInstitution($institution);
        $service->reconcileInstitution($institution);

        $this->assertDatabaseHas('usage_statistics', [
            'institution_id' => $institution->id,
            'metric' => 'students',
            'used_value' => 2,
        ]);

        $this->assertSame(
            1,
            \App\Models\UsageStatistic::query()
                ->where('institution_id', $institution->id)
                ->where('metric', 'students')
                ->count()
        );
    }

    public function test_reconciliation_returns_empty_without_current_subscription(): void
    {
        $institution = Institution::create([
            'name' => 'No Subscription Academy',
            'code' => 'RECON-003',
        ]);

        $this->createStudent($institution, 'active');

        $service = app(UsageReconciliationService::class);

        $result = $service->reconcileInstitution($institution);

        $this->assertSame([], $result);

        $this->assertDatabaseMissing('usage_statistics', [
            'institution_id' => $institution->id,
        ]);
    }

    private function createPlan(Institution $institution): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => 'Reconciliation Plan',
            'code' => 'RECON-PLAN-' . $institution->id,
            'price' => 999,
            'billing_cycle' => 'monthly',
            'trial_days' => 14,
            'max_teachers' => 10,
            'max_students' => 20,
            'max_courses' => 30,
            'storage_limit_mb' => 1000,
            'included_ai_credits' => 100,
            'status' => 'active',
        ]);
    }

    private function createSubscription(
        Institution $institution,
        SubscriptionPlan $plan
    ): Subscription {
        return Subscription::create([
            'uuid' => (string) Str::uuid(),
            'institution_id' => $institution->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);
    }

    private function createStudent(
        Institution $institution,
        string $status
    ): StudentProfile {
        return StudentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => User::factory()->create()->id,
            'status' => $status,
        ]);
    }

    private function createTeacher(
        Institution $institution,
        string $status
    ): TeacherProfile {
        return TeacherProfile::create([
            'institution_id' => $institution->id,
            'user_id' => User::factory()->create()->id,
            'status' => $status,
        ]);
    }

    private function createCourse(
        Institution $institution,
        string $status
    ): Course {
        return Course::create([
            'institution_id' => $institution->id,
            'title' => 'Course ' . Str::random(6),
            'slug' => 'course-' . Str::lower(Str::random(12)),
            'status' => $status,
        ]);
    }
}
