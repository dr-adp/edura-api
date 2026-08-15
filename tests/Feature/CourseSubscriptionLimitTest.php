<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\InstitutionUser;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\UsageStatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CourseSubscriptionLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_institution_admin_can_create_course_within_plan_limit(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $plan = $this->createPlan(
            code: 'COURSE-LIMIT-2',
            maxCourses: 2
        );

        $this->createSubscription($institution, $plan);

        $this->authenticate($admin);

        $response = $this->postJson('/api/courses', [
            'institution_id' => $institution->id,
            'title' => 'Mathematics',
            'status' => 'draft',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.institution_id', $institution->id);

        $this->assertDatabaseHas('courses', [
            'institution_id' => $institution->id,
            'title' => 'Mathematics',
            'status' => 'draft',
        ]);

        $this->assertDatabaseHas('usage_statistics', [
            'institution_id' => $institution->id,
            'metric' => 'courses',
            'used_value' => 1,
        ]);
    }

    public function test_course_creation_is_rejected_when_plan_limit_is_reached(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $plan = $this->createPlan(
            code: 'COURSE-LIMIT-1',
            maxCourses: 1
        );

        $this->createSubscription($institution, $plan);

        $existingCourse = \App\Models\Course::create([
            'institution_id' => $institution->id,
            'title' => 'Existing Course',
            'slug' => 'existing-course-' . Str::random(8),
            'status' => 'draft',
        ]);

        resolve(UsageStatisticsService::class)->increment(
            institution: $institution->id,
            metric: 'courses',
            amount: 1
        );

        $this->authenticate($admin);

        $response = $this->postJson('/api/courses', [
            'institution_id' => $institution->id,
            'title' => 'Second Course',
            'status' => 'draft',
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseMissing('courses', [
            'institution_id' => $institution->id,
            'title' => 'Second Course',
        ]);
    }

    public function test_archived_course_does_not_consume_course_limit(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $plan = $this->createPlan(
            code: 'COURSE-LIMIT-1-ARCHIVED',
            maxCourses: 1
        );

        $this->createSubscription($institution, $plan);

        $existingCourse = \App\Models\Course::create([
            'institution_id' => $institution->id,
            'title' => 'Existing Course',
            'slug' => 'existing-course-' . Str::random(8),
            'status' => 'draft',
        ]);

        resolve(UsageStatisticsService::class)->increment(
            institution: $institution->id,
            metric: 'courses',
            amount: 1
        );

        $this->authenticate($admin);

        $response = $this->postJson('/api/courses', [
            'institution_id' => $institution->id,
            'title' => 'Archived Course',
            'status' => 'archived',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'archived');

        $this->assertDatabaseHas('courses', [
            'institution_id' => $institution->id,
            'title' => 'Archived Course',
            'status' => 'archived',
        ]);

        $this->assertDatabaseHas('usage_statistics', [
            'institution_id' => $institution->id,
            'metric' => 'courses',
            'used_value' => 1,
        ]);
    }

    public function test_billable_course_creation_requires_current_subscription(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $this->authenticate($admin);

        $response = $this->postJson('/api/courses', [
            'institution_id' => $institution->id,
            'title' => 'Course Without Subscription',
            'status' => 'draft',
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseMissing('courses', [
            'institution_id' => $institution->id,
            'title' => 'Course Without Subscription',
        ]);
    }

    public function test_archived_course_can_be_created_without_current_subscription(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $this->authenticate($admin);

        $response = $this->postJson('/api/courses', [
            'institution_id' => $institution->id,
            'title' => 'Archived Without Subscription',
            'status' => 'archived',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'archived');

        $this->assertDatabaseHas('courses', [
            'institution_id' => $institution->id,
            'title' => 'Archived Without Subscription',
            'status' => 'archived',
        ]);

        $this->assertDatabaseMissing('usage_statistics', [
            'institution_id' => $institution->id,
            'metric' => 'courses',
        ]);
    }

    public function test_institution_admin_cannot_create_course_for_another_institution(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $otherInstitution = Institution::create([
            'name' => 'Other Institution',
            'code' => 'OTHER-COURSE-' . Str::upper(Str::random(6)),
        ]);

        $plan = $this->createPlan(
            code: 'COURSE-TENANT',
            maxCourses: 10
        );

        $this->createSubscription($institution, $plan);
        $this->createSubscription($otherInstitution, $plan);

        $this->authenticate($admin);

        $response = $this->postJson('/api/courses', [
            'institution_id' => $otherInstitution->id,
            'title' => 'Unauthorized Course',
            'status' => 'draft',
        ]);

        $response->assertStatus(403);

        $this->assertDatabaseMissing('courses', [
            'institution_id' => $otherInstitution->id,
            'title' => 'Unauthorized Course',
        ]);
    }

    private function createInstitutionAdmin(): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::firstOrCreate(
            [
                'name' => 'institution-admin',
                'guard_name' => 'web',
            ],
            [
                'display_name' => 'Institution Admin',
            ]
        );

        $institution = Institution::create([
            'name' => 'Course Limit Academy',
            'code' => 'COURSE-' . Str::upper(Str::random(6)),
        ]);

        $admin = User::factory()->create();

        $admin->assignRole($role);

        InstitutionUser::create([
            'institution_id' => $institution->id,
            'user_id' => $admin->id,
            'role_in_institution' => 'admin',
            'status' => 'active',
        ]);

        return [$institution, $admin];
    }

    private function createPlan(
        string $code,
        int $maxCourses
    ): SubscriptionPlan {
        return SubscriptionPlan::create([
            'name' => 'Course Limit Plan',
            'code' => $code,
            'price' => 999,
            'billing_cycle' => 'monthly',
            'trial_days' => 14,
            'max_teachers' => 10,
            'max_students' => 10,
            'max_courses' => $maxCourses,
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

    private function authenticate(User $user): void
    {
        $this->actingAs($user, 'web');
    }
}
