<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\InstitutionUser;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StudentSubscriptionLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_institution_admin_can_create_student_within_plan_limit(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $plan = $this->createPlan(
            code: 'STUDENT-LIMIT-2',
            maxStudents: 2
        );

        $this->createSubscription($institution, $plan);

        $studentUser = User::factory()->create();

        $this->authenticate($admin);

        $response = $this->postJson('/api/student-profiles', [
            'institution_id' => $institution->id,
            'user_id' => $studentUser->id,
            'status' => 'active',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.institution_id', $institution->id);

        $this->assertDatabaseHas('student_profiles', [
            'institution_id' => $institution->id,
            'user_id' => $studentUser->id,
        ]);

        $this->assertDatabaseHas('usage_statistics', [
            'institution_id' => $institution->id,
            'metric' => 'students',
            'used_value' => 1,
        ]);
    }

    public function test_student_creation_is_rejected_when_plan_limit_is_reached(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $plan = $this->createPlan(
            code: 'STUDENT-LIMIT-1',
            maxStudents: 1
        );

        $this->createSubscription($institution, $plan);

        $existingStudentUser = User::factory()->create();

        StudentProfile::create([
            'institution_id' => $institution->id,
            'user_id' => $existingStudentUser->id,
            'status' => 'active',
        ]);

        resolve(\App\Services\UsageStatisticsService::class)
            ->increment(
                institution: $institution->id,
                metric: 'students',
                amount: 1
            );

        $studentUser = User::factory()->create();

        $this->authenticate($admin);

        $response = $this->postJson('/api/student-profiles', [
            'institution_id' => $institution->id,
            'user_id' => $studentUser->id,
            'status' => 'active',
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseMissing('student_profiles', [
            'institution_id' => $institution->id,
            'user_id' => $studentUser->id,
        ]);
    }

    public function test_institution_admin_cannot_create_student_for_another_institution(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $otherInstitution = Institution::create([
            'name' => 'Other Institution',
            'code' => 'OTHER-STUDENT',
        ]);

        $plan = $this->createPlan(
            code: 'STUDENT-TENANT',
            maxStudents: 10
        );

        $this->createSubscription($institution, $plan);
        $this->createSubscription($otherInstitution, $plan);

        $studentUser = User::factory()->create();

        $this->authenticate($admin);

        $response = $this->postJson('/api/student-profiles', [
            'institution_id' => $otherInstitution->id,
            'user_id' => $studentUser->id,
            'status' => 'active',
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseMissing('student_profiles', [
            'institution_id' => $otherInstitution->id,
            'user_id' => $studentUser->id,
        ]);
    }

    public function test_student_creation_requires_current_subscription(): void
    {
        [$institution, $admin] = $this->createInstitutionAdmin();

        $studentUser = User::factory()->create();

        $this->authenticate($admin);

        $response = $this->postJson('/api/student-profiles', [
            'institution_id' => $institution->id,
            'user_id' => $studentUser->id,
            'status' => 'active',
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseMissing('student_profiles', [
            'institution_id' => $institution->id,
            'user_id' => $studentUser->id,
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
            'name' => 'Student Limit Academy',
            'code' => 'STUDENT-' . Str::upper(Str::random(6)),
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
        int $maxStudents
    ): SubscriptionPlan {
        return SubscriptionPlan::create([
            'name' => 'Student Limit Plan',
            'code' => $code,
            'price' => 999,
            'billing_cycle' => 'monthly',
            'trial_days' => 14,
            'max_teachers' => 10,
            'max_students' => $maxStudents,
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
