<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\AICreditService;
use App\Services\FeatureService;
use App\Services\SubscriptionService;
use App\Exceptions\DomainException;

use App\Events\AuditLogRequested;
use App\Models\ActivityLog;
use App\Models\Course;
use App\Models\Institution;
use App\Models\InstitutionSetting;
use App\Models\InstitutionUser;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_service_records_context_values_and_redacts_sensitive_data(): void
    {
        $institution = $this->createInstitution('AUDIT-A');
        $admin = $this->createInstitutionAdmin($institution);
        $setting = InstitutionSetting::create([
            'institution_id' => $institution->id,
            'group' => 'mail',
            'key' => 'smtp_password',
            'value' => ['encrypted' => 'cipher-text'],
            'value_type' => 'string',
            'is_encrypted' => true,
            'status' => 'active',
        ]);
        $request = Request::create(
            '/api/institution-settings/' . $setting->id,
            'PATCH',
            server: [
                'REMOTE_ADDR' => '203.0.113.10',
                'HTTP_USER_AGENT' => 'Audit Test Agent',
                'HTTP_X_REQUEST_ID' => 'req-123',
            ]
        );

        $log = app(AuditLogService::class)->recordUpdated(
            auditable: $setting,
            description: 'Institution settings updated.',
            oldValues: [
                'key' => 'smtp_password',
                'value' => ['encrypted' => 'old-payload'],
                'api_key' => 'old-key',
                'status' => 'active',
            ],
            newValues: [
                'key' => 'smtp_password',
                'value' => ['encrypted' => 'new-payload'],
                'api_key' => 'new-key',
                'status' => 'inactive',
            ],
            metadata: [
                'source' => 'settings-api',
                'token' => 'secret-token',
            ],
            user: $admin,
            request: $request
        );

        $this->assertSame($institution->id, $log->institution_id);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('updated', $log->action);
        $this->assertSame(InstitutionSetting::class, $log->auditable_type);
        $this->assertSame($setting->id, $log->auditable_id);
        $this->assertSame('203.0.113.10', $log->ip_address);
        $this->assertSame('Audit Test Agent', $log->user_agent);
        $this->assertSame('req-123', $log->request_id);
        $this->assertSame('[REDACTED]', $log->old_values['value']);
        $this->assertSame('[REDACTED]', $log->new_values['value']);
        $this->assertSame('[REDACTED]', $log->old_values['api_key']);
        $this->assertSame('[REDACTED]', $log->new_values['api_key']);
        $this->assertSame('[REDACTED]', $log->metadata['token']);
        $this->assertSame('settings-api', $log->metadata['source']);
    }

    public function test_system_generated_audit_record_can_have_no_user(): void
    {
        $institution = $this->createInstitution('AUDIT-SYSTEM');

        $log = app(AuditLogService::class)->recordCustom(
            action: 'expired',
            description: 'Subscription expired automatically.',
            metadata: ['job' => 'subscription-expiry'],
            institutionId: $institution->id,
            module: 'Subscription',
            useAuthenticatedUser: false
        );

        $this->assertSame($institution->id, $log->institution_id);
        $this->assertNull($log->user_id);
        $this->assertSame('expired', $log->action);
        $this->assertSame('subscription-expiry', $log->metadata['job']);
    }

    public function test_timeline_enforces_tenant_isolation_and_newest_first_ordering(): void
    {
        $firstInstitution = $this->createInstitution('AUDIT-TENANT-A');
        $secondInstitution = $this->createInstitution('AUDIT-TENANT-B');
        $admin = $this->createInstitutionAdmin($firstInstitution);
        $older = $this->createLog($firstInstitution, [
            'description' => 'Older first tenant activity.',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        $newer = $this->createLog($firstInstitution, [
            'description' => 'Newer first tenant activity.',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        $this->createLog($secondInstitution, [
            'description' => 'Second tenant activity.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->authenticate($admin);

        $this->getJson('/api/audit-logs?per_page=10')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.data.0.id', $newer->id)
            ->assertJsonPath('data.data.1.id', $older->id);
    }

    public function test_timeline_filters_action_user_auditable_type_dates_search_and_paginates(): void
    {
        $institution = $this->createInstitution('AUDIT-FILTER');
        $admin = $this->createInstitutionAdmin($institution);
        $actor = User::factory()->create();
        $otherActor = User::factory()->create();

        $target = $this->createLog($institution, [
            'user_id' => $actor->id,
            'action' => 'created',
            'description' => 'Feature assigned to institution.',
            'auditable_type' => Course::class,
            'auditable_id' => 10,
            'model_type' => Course::class,
            'model_id' => 10,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $this->createLog($institution, [
            'user_id' => $otherActor->id,
            'action' => 'updated',
            'description' => 'Subscription activated.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createLog($institution, [
            'user_id' => $actor->id,
            'action' => 'created',
            'description' => 'Outside date range.',
            'auditable_type' => User::class,
            'auditable_id' => $actor->id,
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        $this->authenticate($admin);

        $this->getJson('/api/audit-logs?action=updated&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.action', 'updated');

        $this->getJson('/api/audit-logs?user_id=' . $actor->id . '&per_page=10')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');

        $this->getJson('/api/audit-logs?auditable_type=' . urlencode(Course::class) . '&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $target->id);

        $this->getJson('/api/audit-logs?date_from=' . now()->subDays(2)->toDateString() . '&date_to=' . now()->toDateString() . '&per_page=10')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');

        $this->getJson('/api/audit-logs?search=' . urlencode('Feature assigned') . '&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $target->id);

        $this->getJson('/api/audit-logs?per_page=2')
            ->assertOk()
            ->assertJsonPath('data.per_page', 2)
            ->assertJsonPath('data.total', 3)
            ->assertJsonCount(2, 'data.data');
    }

    public function test_authorization_blocks_ordinary_users_and_cross_tenant_show_access(): void
    {
        $firstInstitution = $this->createInstitution('AUDIT-AUTH-A');
        $secondInstitution = $this->createInstitution('AUDIT-AUTH-B');
        $admin = $this->createInstitutionAdmin($firstInstitution);
        $student = $this->createUserWithRole('student');
        $superAdmin = $this->createUserWithRole('super-admin', ['view audit logs']);
        $otherTenantLog = $this->createLog($secondInstitution);

        $this->authenticate($student);
        $this->getJson('/api/audit-logs')->assertForbidden();

        $this->authenticate($admin);
        $this->getJson('/api/audit-logs/' . $otherTenantLog->id)->assertForbidden();

        $this->authenticate($superAdmin);
        $this->getJson('/api/audit-logs/' . $otherTenantLog->id)
            ->assertOk()
            ->assertJsonPath('data.id', $otherTenantLog->id);
    }

    public function test_institution_admin_without_active_profile_cannot_view_audit_logs(): void
    {
        $admin = $this->createUserWithRole('institution-admin', ['view audit logs']);

        $this->authenticate($admin);

        $this->getJson('/api/audit-logs')->assertForbidden();
    }

    public function test_audit_event_listener_records_requested_audit_log(): void
    {
        $institution = $this->createInstitution('AUDIT-EVENT');
        $admin = $this->createInstitutionAdmin($institution);

        Event::dispatch(new AuditLogRequested(
            action: 'assigned',
            description: 'Feature assigned to institution.',
            metadata: ['feature' => 'ai_reports'],
            institutionId: $institution->id,
            user: $admin,
            module: 'Feature'
        ));

        $this->assertDatabaseHas('activity_logs', [
            'institution_id' => $institution->id,
            'user_id' => $admin->id,
            'module' => 'Feature',
            'action' => 'assigned',
            'description' => 'Feature assigned to institution.',
        ]);
    }

    public function test_course_observer_records_create_update_and_delete_audit_logs(): void
    {
        $institution = $this->createInstitution('AUDIT-COURSE');
        $admin = $this->createInstitutionAdmin($institution);

        $this->authenticate($admin);

        $course = Course::create([
            'institution_id' => $institution->id,
            'title' => 'Intro Algebra',
            'slug' => 'intro-algebra',
            'status' => 'draft',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'institution_id' => $institution->id,
            'user_id' => $admin->id,
            'action' => 'created',
            'auditable_type' => Course::class,
            'auditable_id' => $course->id,
        ]);

        $course->update(['title' => 'Advanced Algebra']);

        $updateLog = ActivityLog::query()
            ->where('auditable_type', Course::class)
            ->where('auditable_id', $course->id)
            ->where('action', 'updated')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('Intro Algebra', $updateLog->old_values['title']);
        $this->assertSame('Advanced Algebra', $updateLog->new_values['title']);

        $course->delete();

        $this->assertDatabaseHas('activity_logs', [
            'institution_id' => $institution->id,
            'user_id' => $admin->id,
            'action' => 'deleted',
            'auditable_type' => Course::class,
            'auditable_id' => $course->id,
        ]);
    }

    public function test_subscription_service_records_activation_audit(): void
    {
        $institution = $this->createInstitution('AUDIT-SUBSCRIPTION');
        $admin = $this->createInstitutionAdmin($institution);

        $plan = SubscriptionPlan::create([
            'name' => 'Audit Test Plan',
            'code' => 'AUDIT-TEST-PLAN',
            'price' => 999,
            'billing_cycle' => 'monthly',
            'trial_days' => 14,
            'max_teachers' => 10,
            'max_students' => 100,
            'max_courses' => 10,
            'storage_limit_mb' => 1000,
            'status' => 'active',
        ]);

        $subscription = Subscription::create([
            'uuid' => (string) Str::uuid(),
            'institution_id' => $institution->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_TRIAL,
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'trial_ends_at' => now()->addDays(14),
            'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);

        $this->authenticate($admin);

        $result = app(SubscriptionService::class)->activate($subscription);

        $this->assertSame(
            Subscription::STATUS_ACTIVE,
            $result->status
        );

        $this->assertDatabaseHas('activity_logs', [
            'institution_id' => $institution->id,
            'user_id' => $admin->id,
            'module' => 'Subscription',
            'action' => 'subscription_activated',
            'auditable_type' => Subscription::class,
            'auditable_id' => $subscription->id,
        ]);
    }

    public function test_feature_service_records_plan_feature_assignment_audit(): void
    {
        $admin = $this->createUserWithRole('super-admin');

        $plan = SubscriptionPlan::create([
            'name' => 'Feature Audit Plan',
            'code' => 'FEATURE-AUDIT-PLAN',
            'price' => 999,
            'billing_cycle' => 'monthly',
            'trial_days' => 14,
            'max_teachers' => 10,
            'max_students' => 100,
            'max_courses' => 10,
            'storage_limit_mb' => 1000,
            'status' => 'active',
        ]);

        $feature = Feature::create([
            'name' => 'AI Reports Audit Test',
            'code' => 'audit_ai_reports',
            'description' => 'Audit test feature.',
            'status' => 'active',
        ]);

        $this->authenticate($admin);

        $planFeature = app(FeatureService::class)->assignToPlan([
            'subscription_plan_id' => $plan->id,
            'feature_id' => $feature->id,
            'enabled' => true,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'module' => 'Feature',
            'action' => 'plan_feature_assigned',
            'auditable_type' => PlanFeature::class,
            'auditable_id' => $planFeature->id,
        ]);
    }

    public function test_ai_credit_service_records_credit_audit(): void
    {
        $institution = $this->createInstitution('AUDIT-AI-CREDIT');
        $admin = $this->createInstitutionAdmin($institution);

        $this->authenticate($admin);

        $transaction = app(AICreditService::class)->grant([
            'institution_id' => $institution->id,
            'credits' => 100,
            'description' => 'Initial AI credit grant.',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'institution_id' => $institution->id,
            'user_id' => $admin->id,
            'module' => 'AICredit',
            'action' => 'ai_credits_granted',
            'auditable_type' => $transaction::class,
            'auditable_id' => $transaction->id,
        ]);
    }

    public function test_subscription_service_allows_trial_to_active_transition(): void
    {
        $institution = $this->createInstitution('LIFECYCLE-TRIAL-ACTIVE');
        $admin = $this->createInstitutionAdmin($institution);

        $plan = SubscriptionPlan::create([
            'name' => 'Lifecycle Trial Plan',
            'code' => 'LIFECYCLE-TRIAL-PLAN',
            'price' => 999,
            'billing_cycle' => 'monthly',
            'trial_days' => 14,
            'max_teachers' => 10,
            'max_students' => 100,
            'max_courses' => 10,
            'storage_limit_mb' => 1000,
            'status' => 'active',
        ]);

        $subscription = Subscription::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'institution_id' => $institution->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_TRIAL,
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'trial_ends_at' => now()->addDays(14),
            'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);

        $this->authenticate($admin);

        $result = app(SubscriptionService::class)->activate($subscription);

        $this->assertSame(
            Subscription::STATUS_ACTIVE,
            $result->status
        );
    }

    public function test_subscription_service_allows_active_to_suspended_transition(): void
    {
        $institution = $this->createInstitution('LIFECYCLE-ACTIVE-SUSPENDED');
        $admin = $this->createInstitutionAdmin($institution);

        $plan = SubscriptionPlan::create([
            'name' => 'Lifecycle Active Plan',
            'code' => 'LIFECYCLE-ACTIVE-PLAN',
            'price' => 999,
            'billing_cycle' => 'monthly',
            'trial_days' => 14,
            'max_teachers' => 10,
            'max_students' => 100,
            'max_courses' => 10,
            'storage_limit_mb' => 1000,
            'status' => 'active',
        ]);

        $subscription = Subscription::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'institution_id' => $institution->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);

        $this->authenticate($admin);

        $result = app(SubscriptionService::class)->suspend($subscription);

        $this->assertSame(
            Subscription::STATUS_SUSPENDED,
            $result->status
        );
    }

    public function test_subscription_service_allows_suspended_to_active_transition(): void
    {
        $institution = $this->createInstitution('LIFECYCLE-SUSPENDED-ACTIVE');
        $admin = $this->createInstitutionAdmin($institution);

        $plan = SubscriptionPlan::create([
            'name' => 'Lifecycle Suspended Plan',
            'code' => 'LIFECYCLE-SUSPENDED-PLAN',
            'price' => 999,
            'billing_cycle' => 'monthly',
            'trial_days' => 14,
            'max_teachers' => 10,
            'max_students' => 100,
            'max_courses' => 10,
            'storage_limit_mb' => 1000,
            'status' => 'active',
        ]);

        $subscription = Subscription::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'institution_id' => $institution->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_SUSPENDED,
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'suspended_at' => now(),
            'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);

        $this->authenticate($admin);

        $result = app(SubscriptionService::class)->activate($subscription);

        $this->assertSame(
            Subscription::STATUS_ACTIVE,
            $result->status
        );
    }

    public function test_cancelled_subscription_cannot_be_activated(): void
    {
        $institution = $this->createInstitution('LIFECYCLE-CANCELLED');
        $admin = $this->createInstitutionAdmin($institution);

        $plan = SubscriptionPlan::create([
            'name' => 'Cancelled Lifecycle Plan',
            'code' => 'LIFECYCLE-CANCELLED-PLAN',
            'price' => 999,
            'billing_cycle' => 'monthly',
            'trial_days' => 14,
            'max_teachers' => 10,
            'max_students' => 100,
            'max_courses' => 10,
            'storage_limit_mb' => 1000,
            'status' => 'active',
        ]);

        $subscription = Subscription::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'institution_id' => $institution->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_CANCELLED,
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'cancelled_at' => now(),
            'expires_at' => now(),
        ]);

        $this->authenticate($admin);

        $this->expectException(DomainException::class);

        app(SubscriptionService::class)->activate($subscription);
    }

    public function test_expired_subscription_cannot_be_activated(): void
    {
        $institution = $this->createInstitution('LIFECYCLE-EXPIRED');
        $admin = $this->createInstitutionAdmin($institution);

        $plan = SubscriptionPlan::create([
            'name' => 'Expired Lifecycle Plan',
            'code' => 'LIFECYCLE-EXPIRED-PLAN',
            'price' => 999,
            'billing_cycle' => 'monthly',
            'trial_days' => 14,
            'max_teachers' => 10,
            'max_students' => 100,
            'max_courses' => 10,
            'storage_limit_mb' => 1000,
            'status' => 'active',
        ]);

        $subscription = Subscription::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'institution_id' => $institution->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_EXPIRED,
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'expires_at' => now(),
        ]);

        $this->authenticate($admin);

        $this->expectException(DomainException::class);

        app(SubscriptionService::class)->activate($subscription);
    }

    public function test_active_subscription_cannot_transition_back_to_trial(): void
    {
        $institution = $this->createInstitution('LIFECYCLE-ACTIVE-TRIAL');
        $admin = $this->createInstitutionAdmin($institution);

        $plan = SubscriptionPlan::create([
            'name' => 'Active Trial Prevention Plan',
            'code' => 'LIFECYCLE-ACTIVE-TRIAL',
            'price' => 999,
            'billing_cycle' => 'monthly',
            'trial_days' => 14,
            'max_teachers' => 10,
            'max_students' => 100,
            'max_courses' => 10,
            'storage_limit_mb' => 1000,
            'status' => 'active',
        ]);

        $subscription = Subscription::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'institution_id' => $institution->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);

        $this->authenticate($admin);

        $this->expectException(DomainException::class);

        app(SubscriptionService::class)->update(
            $subscription,
            ['status' => Subscription::STATUS_TRIAL]
        );
    }

    private function createInstitution(string $code): Institution
    {
        return Institution::create([
            'name' => 'Institution ' . $code,
            'code' => $code,
        ]);
    }

    private function createInstitutionAdmin(Institution $institution): User
    {
        $user = $this->createUserWithRole('institution-admin', ['view audit logs']);

        InstitutionUser::create([
            'institution_id' => $institution->id,
            'user_id' => $user->id,
            'role_in_institution' => 'admin',
            'status' => 'active',
        ]);

        return $user;
    }

    private function createUserWithRole(
        string $roleName,
        array $permissions = []
    ): User {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $role = Role::firstOrCreate(
            [
                'name' => $roleName,
                'guard_name' => 'web',
            ],
            [
                'display_name' => Str::headline($roleName),
            ]
        );

        if ($permissions !== []) {
            $role->givePermissionTo($permissions);
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function authenticate(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web');
    }

    private function createLog(
        Institution $institution,
        array $overrides = []
    ): ActivityLog {
        $timestamps = [
            'created_at' => $overrides['created_at'] ?? now(),
            'updated_at' => $overrides['updated_at'] ?? now(),
        ];

        unset($overrides['created_at'], $overrides['updated_at']);

        $log = ActivityLog::create(array_merge([
            'institution_id' => $institution->id,
            'user_id' => null,
            'module' => 'System',
            'action' => 'created',
            'description' => 'Audit activity recorded.',
            'auditable_type' => null,
            'auditable_id' => null,
            'model_type' => null,
            'model_id' => null,
            'metadata' => null,
            'properties' => null,
        ], $overrides));

        $log->forceFill($timestamps)->save();

        return $log->fresh();
    }
}
