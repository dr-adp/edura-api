<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\InstitutionUser;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TeacherSubscriptionLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_institution_admin_can_create_active_teacher_within_plan_limit(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $plan = $this->createPlan(
            code: 'TEACHER-LIMIT-2',
            maxTeachers: 2
        );

        $this->createSubscription($institution, $plan);

        $teacherUser = User::factory()->create();

        $this->authenticate($admin);

        $response = $this->postJson('/api/teacher-profiles', [
            'institution_id' => $institution->id,
            'user_id' => $teacherUser->id,
            'status' => 'active',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.institution_id', $institution->id);

        $this->assertDatabaseHas('teacher_profiles', [
            'institution_id' => $institution->id,
            'user_id' => $teacherUser->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('usage_statistics', [
            'institution_id' => $institution->id,
            'metric' => 'teachers',
            'used_value' => 1,
        ]);
    }

    public function test_active_teacher_creation_is_rejected_when_plan_limit_is_reached(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $plan = $this->createPlan(
            code: 'TEACHER-LIMIT-1',
            maxTeachers: 1
        );

        $this->createSubscription($institution, $plan);

        $existingTeacherUser = User::factory()->create();

        TeacherProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $existingTeacherUser->id,
            'status' => 'active',
        ]);

        resolve(\App\Services\UsageStatisticsService::class)
            ->increment(
                institution: $institution->id,
                metric: 'teachers',
                amount: 1
            );

        $teacherUser = User::factory()->create();

        $this->authenticate($admin);

        $response = $this->postJson('/api/teacher-profiles', [
            'institution_id' => $institution->id,
            'user_id' => $teacherUser->id,
            'status' => 'active',
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseMissing('teacher_profiles', [
            'institution_id' => $institution->id,
            'user_id' => $teacherUser->id,
        ]);
    }

    public function test_inactive_teacher_does_not_consume_teacher_capacity(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $plan = $this->createPlan(
            code: 'TEACHER-LIMIT-INACTIVE',
            maxTeachers: 1
        );

        $this->createSubscription($institution, $plan);

        $existingTeacherUser = User::factory()->create();

        TeacherProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $existingTeacherUser->id,
            'status' => 'active',
        ]);

        resolve(\App\Services\UsageStatisticsService::class)
            ->increment(
                institution: $institution->id,
                metric: 'teachers',
                amount: 1
            );

        $teacherUser = User::factory()->create();

        $this->authenticate($admin);

        $response = $this->postJson('/api/teacher-profiles', [
            'institution_id' => $institution->id,
            'user_id' => $teacherUser->id,
            'status' => 'inactive',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('teacher_profiles', [
            'institution_id' => $institution->id,
            'user_id' => $teacherUser->id,
            'status' => 'inactive',
        ]);
    }

    public function test_institution_admin_cannot_create_teacher_for_another_institution(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $otherInstitution = Institution::create([
            'name' => 'Other Teacher Institution',
            'code' => 'OTHER-TEACHER',
        ]);

        $plan = $this->createPlan(
            code: 'TEACHER-TENANT',
            maxTeachers: 10
        );

        $this->createSubscription($institution, $plan);
        $this->createSubscription($otherInstitution, $plan);

        $teacherUser = User::factory()->create();

        $this->authenticate($admin);

        $response = $this->postJson('/api/teacher-profiles', [
            'institution_id' => $otherInstitution->id,
            'user_id' => $teacherUser->id,
            'status' => 'active',
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseMissing('teacher_profiles', [
            'institution_id' => $otherInstitution->id,
            'user_id' => $teacherUser->id,
        ]);
    }

    public function test_teacher_creation_requires_current_subscription(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $teacherUser = User::factory()->create();

        $this->authenticate($admin);

        $response = $this->postJson('/api/teacher-profiles', [
            'institution_id' => $institution->id,
            'user_id' => $teacherUser->id,
            'status' => 'active',
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseMissing('teacher_profiles', [
            'institution_id' => $institution->id,
            'user_id' => $teacherUser->id,
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
            'name' => 'Teacher Limit Academy',
            'code' => 'TEACHER-' . Str::upper(Str::random(6)),
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
        int $maxTeachers
    ): SubscriptionPlan {
        return SubscriptionPlan::create([
            'name' => 'Teacher Limit Plan',
            'code' => $code,
            'price' => 999,
            'billing_cycle' => 'monthly',
            'trial_days' => 14,
            'max_teachers' => $maxTeachers,
            'max_students' => 20,
            'max_courses' => 10,
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
