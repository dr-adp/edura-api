<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Institution;
use App\Models\InstitutionUser;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CourseLifecycleSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_to_archived_releases_course_usage(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $plan = $this->createPlan(
            code: 'LIFECYCLE-ARCHIVE-1',
            maxCourses: 5
        );

        $this->createSubscription($institution, $plan);

        $this->authenticate($admin);

        $response = $this->postJson('/api/courses', [
            'institution_id' => $institution->id,
            'title' => 'Draft Course',
            'status' => 'draft',
        ]);

        $response->assertCreated();

        $courseId = $response->json('data.id');

        $this->assertUsage($institution, 1);

        $response = $this->putJson(
            "/api/courses/{$courseId}",
            [
                'status' => 'archived',
            ]
        );

        $response->assertOk();

        $this->assertUsage($institution, 0);
    }

    public function test_published_to_archived_releases_course_usage(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $plan = $this->createPlan(
            code: 'LIFECYCLE-PUBLISHED-1',
            maxCourses: 5
        );

        $this->createSubscription($institution, $plan);

        $this->authenticate($admin);

        $response = $this->postJson('/api/courses', [
            'institution_id' => $institution->id,
            'title' => 'Published Course',
            'status' => 'published',
        ]);

        $response->assertCreated();

        $courseId = $response->json('data.id');

        $this->assertUsage($institution, 1);

        $response = $this->putJson(
            "/api/courses/{$courseId}",
            [
                'status' => 'archived',
            ]
        );

        $response->assertOk();

        $this->assertUsage($institution, 0);
    }

    public function test_archived_to_draft_consumes_course_usage(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $plan = $this->createPlan(
            code: 'LIFECYCLE-RESTORE-DRAFT-1',
            maxCourses: 5
        );

        $this->createSubscription($institution, $plan);

        $this->authenticate($admin);

        $response = $this->postJson('/api/courses', [
            'institution_id' => $institution->id,
            'title' => 'Archived Course',
            'status' => 'archived',
        ]);

        $response->assertCreated();

        $courseId = $response->json('data.id');

        $this->assertUsage($institution, 0);

        $response = $this->putJson(
            "/api/courses/{$courseId}",
            [
                'status' => 'draft',
            ]
        );

        $response->assertOk();

        $this->assertUsage($institution, 1);
    }

    public function test_archived_to_published_consumes_course_usage(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $plan = $this->createPlan(
            code: 'LIFECYCLE-RESTORE-PUBLISHED-1',
            maxCourses: 5
        );

        $this->createSubscription($institution, $plan);

        $this->authenticate($admin);

        $response = $this->postJson('/api/courses', [
            'institution_id' => $institution->id,
            'title' => 'Archived Course',
            'status' => 'archived',
        ]);

        $response->assertCreated();

        $courseId = $response->json('data.id');

        $this->assertUsage($institution, 0);

        $response = $this->putJson(
            "/api/courses/{$courseId}",
            [
                'status' => 'published',
            ]
        );

        $response->assertOk();

        $this->assertUsage($institution, 1);
    }

    public function test_draft_to_published_does_not_change_course_usage(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $plan = $this->createPlan(
            code: 'LIFECYCLE-DRAFT-PUBLISHED-1',
            maxCourses: 5
        );

        $this->createSubscription($institution, $plan);

        $this->authenticate($admin);

        $response = $this->postJson('/api/courses', [
            'institution_id' => $institution->id,
            'title' => 'Draft Course',
            'status' => 'draft',
        ]);

        $response->assertCreated();

        $courseId = $response->json('data.id');

        $this->assertUsage($institution, 1);

        $response = $this->putJson(
            "/api/courses/{$courseId}",
            [
                'status' => 'published',
            ]
        );

        $response->assertOk();

        $this->assertUsage($institution, 1);
    }

    public function test_published_to_draft_does_not_change_course_usage(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $plan = $this->createPlan(
            code: 'LIFECYCLE-PUBLISHED-DRAFT-1',
            maxCourses: 5
        );

        $this->createSubscription($institution, $plan);

        $this->authenticate($admin);

        $response = $this->postJson('/api/courses', [
            'institution_id' => $institution->id,
            'title' => 'Published Course',
            'status' => 'published',
        ]);

        $response->assertCreated();

        $courseId = $response->json('data.id');

        $this->assertUsage($institution, 1);

        $response = $this->putJson(
            "/api/courses/{$courseId}",
            [
                'status' => 'draft',
            ]
        );

        $response->assertOk();

        $this->assertUsage($institution, 1);
    }

    public function test_archived_to_draft_requires_current_subscription(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $this->authenticate($admin);

        $response = $this->postJson('/api/courses', [
            'institution_id' => $institution->id,
            'title' => 'Archived Course',
            'status' => 'archived',
        ]);

        $response->assertCreated();

        $courseId = $response->json('data.id');

        $response = $this->putJson(
            "/api/courses/{$courseId}",
            [
                'status' => 'draft',
            ]
        );

        $response->assertStatus(422);

        $this->assertUsage($institution, 0);

        $this->assertDatabaseHas('courses', [
            'id' => $courseId,
            'status' => 'archived',
        ]);
    }

    public function test_archived_to_draft_respects_course_limit(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $plan = $this->createPlan(
            code: 'LIFECYCLE-LIMIT-1',
            maxCourses: 1
        );

        $this->createSubscription($institution, $plan);

        $this->authenticate($admin);

        /*
         * First course consumes the only available slot.
         */
        $firstResponse = $this->postJson('/api/courses', [
            'institution_id' => $institution->id,
            'title' => 'Active Course',
            'status' => 'draft',
        ]);

        $firstResponse->assertCreated();

        /*
         * Second course starts archived and therefore does not consume quota.
         */
        $secondResponse = $this->postJson('/api/courses', [
            'institution_id' => $institution->id,
            'title' => 'Archived Course',
            'status' => 'archived',
        ]);

        $secondResponse->assertCreated();

        $secondCourseId = $secondResponse->json('data.id');

        $this->assertUsage($institution, 1);

        /*
         * Restoring the archived course must fail because
         * the plan has no remaining course capacity.
         */
        $response = $this->putJson(
            "/api/courses/{$secondCourseId}",
            [
                'status' => 'draft',
            ]
        );

        $response->assertStatus(422);

        $this->assertUsage($institution, 1);

        $this->assertDatabaseHas('courses', [
            'id' => $secondCourseId,
            'status' => 'archived',
        ]);
    }

    public function test_update_without_status_change_does_not_change_usage(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $plan = $this->createPlan(
            code: 'LIFECYCLE-NO-CHANGE-1',
            maxCourses: 5
        );

        $this->createSubscription($institution, $plan);

        $this->authenticate($admin);

        $response = $this->postJson('/api/courses', [
            'institution_id' => $institution->id,
            'title' => 'Original Course',
            'status' => 'draft',
        ]);

        $response->assertCreated();

        $courseId = $response->json('data.id');

        $this->assertUsage($institution, 1);

        $response = $this->putJson(
            "/api/courses/{$courseId}",
            [
                'title' => 'Updated Course',
            ]
        );

        $response->assertOk();

        $this->assertUsage($institution, 1);
    }

    private function assertUsage(
        Institution $institution,
        int $expected
    ): void {
        $this->assertDatabaseHas('usage_statistics', [
            'institution_id' => $institution->id,
            'metric' => 'courses',
            'used_value' => $expected,
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
            'name' => 'Course Lifecycle Academy',
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
            'name' => 'Course Lifecycle Plan',
            'code' => $code,
            'price' => 999,
            'billing_cycle' => 'monthly',
            'trial_days' => 14,
            'max_teachers' => 10,
            'max_students' => 20,
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
