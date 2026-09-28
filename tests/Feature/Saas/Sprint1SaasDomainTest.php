<?php

namespace Tests\Feature\Saas;

use App\Models\AICreditTransaction;
use App\Models\ActivityLog;
use App\Models\Feature;
use App\Models\Institution;
use App\Models\InstitutionSetting;
use App\Models\InstitutionUser;
use App\Models\PlanFeature;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AICreditService;
use App\Services\AuditLogService;
use App\Services\FeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Sprint1SaasDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_subscription_activation_does_not_duplicate_included_ai_credits(): void
    {
        $superAdmin = $this->createUserWithRole('super-admin');
        $institution = $this->createInstitution('AI-IDEMPOTENT');
        $plan = $this->createPlan('AI-IDEMPOTENT-PLAN');

        $this->actingAs($superAdmin, 'web');

        $response = $this->postJson('/api/subscriptions', [
            'institution_id' => $institution->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->toDateTimeString(),
        ]);

        $response->assertCreated();

        $subscriptionId = $response->json('data.id');

        $this->postJson(
            "/api/subscriptions/{$subscriptionId}/activate"
        )->assertOk();

        $this->assertDatabaseCount('ai_credit_transactions', 1);
        $this->assertDatabaseCount('subscription_ai_credit_grants', 1);

        $this->assertSame(
            100.0,
            (float) app(AICreditService::class)
                ->balance($institution)
        );

        $grant = AICreditTransaction::query()
            ->where('subscription_id', $subscriptionId)
            ->where('source', 'subscription_included')
            ->sole();

        $this->assertDatabaseHas('activity_logs', [
            'institution_id' => $institution->id,
            'user_id' => $superAdmin->id,
            'module' => 'AICredit',
            'action' => 'ai_credits_granted',
            'auditable_type' => AICreditTransaction::class,
            'auditable_id' => $grant->id,
        ]);
        $this->assertSame(1, ActivityLog::query()
            ->where('module', 'AICredit')
            ->where('action', 'ai_credits_granted')
            ->where('auditable_type', AICreditTransaction::class)
            ->where('auditable_id', $grant->id)
            ->count());

        $this->postJson("/api/subscriptions/{$subscriptionId}/suspend")
            ->assertOk();
        $this->postJson("/api/subscriptions/{$subscriptionId}/cancel")
            ->assertOk();

        $this->assertDatabaseHas('ai_credit_transactions', [
            'id' => $grant->id,
            'subscription_id' => $subscriptionId,
            'source' => 'subscription_included',
        ]);
    }

    public function test_trial_subscription_does_not_receive_included_ai_credits_until_activation(): void
    {
        $superAdmin = $this->createUserWithRole('super-admin');
        $institution = $this->createInstitution('AI-TRIAL');
        $plan = $this->createPlan('AI-TRIAL-PLAN');

        $this->actingAs($superAdmin, 'web');

        $response = $this->postJson('/api/subscriptions', [
            'institution_id' => $institution->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'trial',
            'starts_at' => now()->toDateTimeString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'trial');

        $subscriptionId = $response->json('data.id');

        $this->assertDatabaseMissing('ai_credit_transactions', [
            'subscription_id' => $subscriptionId,
            'source' => 'subscription_included',
        ]);

        $this->assertSame(
            0.0,
            (float) app(AICreditService::class)
                ->balance($institution)
        );

        $this->postJson(
            "/api/subscriptions/{$subscriptionId}/activate"
        )->assertOk();

        $this->assertDatabaseHas('ai_credit_transactions', [
            'institution_id' => $institution->id,
            'subscription_id' => $subscriptionId,
            'transaction_type' => 'grant',
            'credits' => 100,
            'source' => 'subscription_included',
        ]);

        $this->assertSame(
            100.0,
            (float) app(AICreditService::class)
                ->balance($institution)
        );
    }

    public function test_updating_trial_subscription_to_active_grants_included_ai_credits(): void
    {
        $superAdmin = $this->createUserWithRole('super-admin');
        $institution = $this->createInstitution('AI-UPDATE-ACTIVATION');
        $plan = $this->createPlan('AI-UPDATE-ACTIVATION-PLAN');

        $this->actingAs($superAdmin, 'web');

        $subscriptionId = $this->postJson('/api/subscriptions', [
            'institution_id' => $institution->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'trial',
            'starts_at' => now()->toDateTimeString(),
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/subscriptions/{$subscriptionId}", [
            'status' => 'active',
        ])->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('ai_credit_transactions', [
            'institution_id' => $institution->id,
            'subscription_id' => $subscriptionId,
            'transaction_type' => 'grant',
            'credits' => 100,
            'source' => 'subscription_included',
        ]);
    }

    public function test_included_ai_credits_preserve_balance_and_are_granted_per_subscription(): void
    {
        $superAdmin = $this->createUserWithRole('super-admin');
        $institution = $this->createInstitution('AI-BALANCE-PRESERVE');
        $plan = $this->createPlan('AI-BALANCE-PRESERVE-PLAN');

        $this->actingAs($superAdmin, 'web');

        app(AICreditService::class)->grant([
            'institution_id' => $institution->id,
            'credits' => 25,
            'source' => 'manual',
        ]);

        $subscriptionIds = [];

        for ($index = 0; $index < 2; $index++) {
            $subscriptionIds[] = $this->postJson('/api/subscriptions', [
                'institution_id' => $institution->id,
                'subscription_plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now()->toDateTimeString(),
            ])->assertCreated()->json('data.id');
        }

        $this->assertSame(
            225.0,
            (float) app(AICreditService::class)->balance($institution)
        );
        $this->assertSame(3, AICreditTransaction::query()
            ->where('institution_id', $institution->id)
            ->count());
        $this->assertDatabaseCount('subscription_ai_credit_grants', 2);

        foreach ($subscriptionIds as $subscriptionId) {
            $this->assertDatabaseHas('ai_credit_transactions', [
                'institution_id' => $institution->id,
                'subscription_id' => $subscriptionId,
                'transaction_type' => 'grant',
                'credits' => 100,
                'source' => 'subscription_included',
            ]);
        }
    }

    public function test_zero_or_negative_plan_credits_do_not_create_included_grants(): void
    {
        $superAdmin = $this->createUserWithRole('super-admin');
        $institution = $this->createInstitution('AI-NO-CREDITS');
        $zeroPlan = $this->createPlan('AI-ZERO-PLAN');
        $negativePlan = $this->createPlan('AI-NEGATIVE-PLAN');
        $zeroPlan->update(['included_ai_credits' => 0]);
        $negativePlan->update(['included_ai_credits' => -10]);

        $this->actingAs($superAdmin, 'web');

        foreach ([$zeroPlan, $negativePlan] as $plan) {
            $subscriptionId = $this->postJson('/api/subscriptions', [
                'institution_id' => $institution->id,
                'subscription_plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now()->toDateTimeString(),
            ])->assertCreated()->json('data.id');

            $this->assertDatabaseMissing('ai_credit_transactions', [
                'subscription_id' => $subscriptionId,
                'source' => 'subscription_included',
            ]);
        }

        $this->assertSame(0, AICreditTransaction::query()
            ->where('institution_id', $institution->id)
            ->count());
        $this->assertDatabaseCount('subscription_ai_credit_grants', 0);
        $this->assertSame(
            0.0,
            (float) app(AICreditService::class)->balance($institution)
        );
    }

    public function test_existing_included_grants_are_backfilled_without_rewriting_ledger_rows(): void
    {
        $superAdmin = $this->createUserWithRole('super-admin');
        $institution = $this->createInstitution('AI-LEGACY-GRANTS');
        $plan = $this->createPlan('AI-LEGACY-GRANTS-PLAN');

        $this->actingAs($superAdmin, 'web');

        $subscriptionId = $this->postJson('/api/subscriptions', [
            'institution_id' => $institution->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'trial',
            'starts_at' => now()->toDateTimeString(),
        ])->assertCreated()->json('data.id');

        foreach ([100, 5] as $credits) {
            AICreditTransaction::create([
                'institution_id' => $institution->id,
                'subscription_id' => $subscriptionId,
                'transaction_type' => 'grant',
                'credits' => $credits,
                'balance_after' => $credits === 100 ? 100 : 105,
                'source' => 'subscription_included',
                'created_by_id' => $superAdmin->id,
            ]);
        }

        $otherInstitution = $this->createInstitution('AI-LEGACY-OTHER');
        $unrelatedSubscription = Subscription::create([
            'uuid' => (string) Str::uuid(),
            'institution_id' => $institution->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'trial',
            'billing_cycle' => 'yearly',
            'starts_at' => now(),
        ]);
        $nonGrantSubscription = Subscription::create([
            'uuid' => (string) Str::uuid(),
            'institution_id' => $otherInstitution->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'trial',
            'billing_cycle' => 'yearly',
            'starts_at' => now(),
        ]);

        AICreditTransaction::create([
            'institution_id' => $otherInstitution->id,
            'subscription_id' => $unrelatedSubscription->id,
            'transaction_type' => 'grant',
            'credits' => 10,
            'balance_after' => 10,
            'source' => 'subscription_included',
        ]);
        AICreditTransaction::create([
            'institution_id' => $otherInstitution->id,
            'subscription_id' => $nonGrantSubscription->id,
            'transaction_type' => 'consume',
            'credits' => -1,
            'balance_after' => 9,
            'source' => 'subscription_included',
        ]);

        $transactionIds = AICreditTransaction::query()
            ->where('subscription_id', $subscriptionId)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        Schema::dropIfExists('subscription_ai_credit_grants');
        $migration = require base_path(
            'database/migrations/2026_09_27_000001_create_subscription_ai_credit_grants_table.php'
        );
        $migration->up();

        $this->assertDatabaseCount('subscription_ai_credit_grants', 1);
        $this->assertDatabaseHas('subscription_ai_credit_grants', [
            'subscription_id' => $subscriptionId,
        ]);
        $this->assertDatabaseMissing('subscription_ai_credit_grants', [
            'subscription_id' => $unrelatedSubscription->id,
        ]);
        $this->assertDatabaseMissing('subscription_ai_credit_grants', [
            'subscription_id' => $nonGrantSubscription->id,
        ]);
        $this->assertDatabaseCount('ai_credit_transactions', 4);

        $existingGrant = app(AICreditService::class)
            ->grantIncludedForSubscription(
                Subscription::findOrFail($subscriptionId),
                [
                    'credits' => 100,
                    'created_by_id' => $superAdmin->id,
                ]
            );

        $this->assertNotNull($existingGrant);
        $this->assertSame($transactionIds[0], $existingGrant->id);
        $this->assertSame(
            $transactionIds,
            AICreditTransaction::query()
                ->where('subscription_id', $subscriptionId)
                ->orderBy('id')
                ->pluck('id')
                ->all()
        );
        $this->assertSame(
            105.0,
            (float) app(AICreditService::class)->balance($institution)
        );
        $this->assertSame(0, ActivityLog::query()
            ->where('institution_id', $institution->id)
            ->where('module', 'AICredit')
            ->where('action', 'ai_credits_granted')
            ->count());
    }

    public function test_included_grant_claim_and_ledger_roll_back_when_audit_logging_fails(): void
    {
        $institution = $this->createInstitution('AI-GRANT-ROLLBACK');
        $plan = $this->createPlan('AI-GRANT-ROLLBACK-PLAN');
        $subscription = Subscription::create([
            'uuid' => (string) Str::uuid(),
            'institution_id' => $institution->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'yearly',
            'starts_at' => now(),
        ]);

        $auditLogService = \Mockery::mock(AuditLogService::class);
        $auditLogService->shouldReceive('recordCustom')
            ->once()
            ->andThrow(new RuntimeException('Audit storage unavailable.'));

        try {
            (new AICreditService($auditLogService))
                ->grantIncludedForSubscription($subscription);
            $this->fail('The audit logging failure should abort the grant.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage unavailable.', $exception->getMessage());
        }

        $this->assertDatabaseCount('subscription_ai_credit_grants', 0);
        $this->assertDatabaseCount('ai_credit_transactions', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertSame(
            0.0,
            (float) (new AICreditService($auditLogService))->balance($institution)
        );
    }

    public function test_ai_credit_api_uses_subscription_included_idempotency(): void
    {
        $superAdmin = $this->createUserWithRole('super-admin');
        $institution = $this->createInstitution('AI-API-IDEMPOTENCY');
        $plan = $this->createPlan('AI-API-IDEMPOTENCY-PLAN');
        $subscription = Subscription::create([
            'uuid' => (string) Str::uuid(),
            'institution_id' => $institution->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'yearly',
            'starts_at' => now(),
        ]);

        $this->actingAs($superAdmin, 'web');

        $payload = [
            'institution_id' => $institution->id,
            'subscription_id' => $subscription->id,
            'transaction_type' => 'grant',
            'credits' => 100,
            'source' => 'subscription_included',
            'description' => 'API included grant.',
        ];

        $otherInstitution = $this->createInstitution('AI-API-WRONG-INSTITUTION');
        $this->postJson('/api/ai-credit-transactions', array_merge(
            $payload,
            ['institution_id' => $otherInstitution->id]
        ))->assertUnprocessable();

        $this->postJson('/api/ai-credit-transactions', array_merge(
            $payload,
            ['credits' => 150]
        ))->assertUnprocessable();

        $first = $this->postJson('/api/ai-credit-transactions', $payload)
            ->assertCreated();
        $duplicate = $this->postJson('/api/ai-credit-transactions', $payload)
            ->assertCreated();

        $this->assertSame($first->json('data.id'), $duplicate->json('data.id'));
        $this->assertDatabaseCount('ai_credit_transactions', 1);
        $this->assertDatabaseCount('subscription_ai_credit_grants', 1);
        $this->assertDatabaseHas('ai_credit_transactions', [
            'id' => $first->json('data.id'),
            'description' => 'API included grant.',
        ]);
        $this->assertSame(
            100.0,
            (float) app(AICreditService::class)->balance($institution)
        );
        $this->assertSame(1, ActivityLog::query()
            ->where('institution_id', $institution->id)
            ->where('module', 'AICredit')
            ->where('action', 'ai_credits_granted')
            ->count());
    }

    public function test_ai_credit_api_does_not_grant_included_credits_to_a_trial(): void
    {
        $superAdmin = $this->createUserWithRole('super-admin');
        $institution = $this->createInstitution('AI-API-TRIAL');
        $plan = $this->createPlan('AI-API-TRIAL-PLAN');

        $this->actingAs($superAdmin, 'web');

        $subscriptionId = $this->postJson('/api/subscriptions', [
            'institution_id' => $institution->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'trial',
            'starts_at' => now()->toDateTimeString(),
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/ai-credit-transactions', [
            'institution_id' => $institution->id,
            'subscription_id' => $subscriptionId,
            'transaction_type' => 'grant',
            'credits' => 100,
            'source' => 'subscription_included',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('ai_credit_transactions', [
            'subscription_id' => $subscriptionId,
            'source' => 'subscription_included',
        ]);
        $this->assertDatabaseCount('subscription_ai_credit_grants', 0);
        $this->assertSame(
            0.0,
            (float) app(AICreditService::class)->balance($institution)
        );
    }

    public function test_included_grant_claim_does_not_restrict_other_credit_transactions(): void
    {
        $superAdmin = $this->createUserWithRole('super-admin');
        $institution = $this->createInstitution('AI-OTHER-TRANSACTIONS');
        $plan = $this->createPlan('AI-OTHER-TRANSACTIONS-PLAN');

        $this->actingAs($superAdmin, 'web');

        $subscriptionId = $this->postJson('/api/subscriptions', [
            'institution_id' => $institution->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->toDateTimeString(),
        ])->assertCreated()->json('data.id');

        foreach ([10, 5] as $credits) {
            app(AICreditService::class)->grant([
                'institution_id' => $institution->id,
                'subscription_id' => $subscriptionId,
                'credits' => $credits,
                'source' => 'manual',
            ]);
        }

        app(AICreditService::class)->consume([
            'institution_id' => $institution->id,
            'subscription_id' => $subscriptionId,
            'credits' => 4,
            'source' => 'ai-service',
        ]);

        $this->assertDatabaseCount('ai_credit_transactions', 4);
        $this->assertDatabaseHas('ai_credit_transactions', [
            'subscription_id' => $subscriptionId,
            'transaction_type' => 'consume',
            'credits' => -4,
            'source' => 'ai-service',
        ]);
        $this->assertSame(
            111.0,
            (float) app(AICreditService::class)->balance($institution)
        );
    }

    public function test_super_admin_creates_subscription_and_feature_service_resolves_plan_feature(): void
    {
        $superAdmin = $this->createUserWithRole('super-admin');
        $institution = $this->createInstitution('SAAS-A');
        $plan = $this->createPlan('PROFESSIONAL');
        $feature = Feature::create([
            'code' => 'ai_reports',
            'name' => 'AI Reports',
            'category' => 'ai',
            'status' => 'active',
        ]);
        PlanFeature::create([
            'subscription_plan_id' => $plan->id,
            'feature_id' => $feature->id,
            'enabled' => true,
            'status' => 'active',
        ]);

        $this->actingAs($superAdmin, 'web');

        $subscriptionId = $this->postJson('/api/subscriptions', [
            'institution_id' => $institution->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->toDateTimeString(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->json('data.id');

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscriptionId,
            'institution_id' => $institution->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('ai_credit_transactions', [
            'institution_id' => $institution->id,
            'subscription_id' => $subscriptionId,
            'transaction_type' => 'grant',
            'credits' => 100,
            'source' => 'subscription_included',
        ]);

        $this->assertSame(
            100.0,
            (float) app(AICreditService::class)
                ->balance($institution)
        );

        $this->assertTrue(
            app(FeatureService::class)->enabled($institution, 'ai_reports')
        );
    }

    public function test_institution_admin_can_manage_only_own_settings(): void
    {
        $firstInstitution = $this->createInstitution('CONFIG-A');
        $secondInstitution = $this->createInstitution('CONFIG-B');
        $admin = $this->createInstitutionAdmin($firstInstitution);

        InstitutionSetting::create([
            'institution_id' => $secondInstitution->id,
            'group' => 'branding',
            'key' => 'primary_color',
            'value' => ['value' => '#111111'],
            'value_type' => 'string',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'web');

        $this->postJson('/api/institution-settings', [
            'institution_id' => $secondInstitution->id,
            'group' => 'branding',
            'key' => 'primary_color',
            'value' => ['value' => '#ff0000'],
            'value_type' => 'string',
        ])->assertForbidden();

        $this->postJson('/api/institution-settings', [
            'institution_id' => $firstInstitution->id,
            'group' => 'branding',
            'key' => 'primary_color',
            'value' => ['value' => '#0055ff'],
            'value_type' => 'string',
            'is_public' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.institution_id', $firstInstitution->id);

        $this->getJson('/api/institution-settings')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.institution_id', $firstInstitution->id);
    }

    public function test_ai_credit_ledger_rejects_over_consumption(): void
    {
        $superAdmin = $this->createUserWithRole('super-admin');
        $institution = $this->createInstitution('AI-A');

        $this->actingAs($superAdmin, 'web');

        $this->postJson('/api/ai-credit-transactions', [
            'institution_id' => $institution->id,
            'transaction_type' => 'grant',
            'credits' => 10,
            'source' => 'manual',
        ])
            ->assertCreated()
            ->assertJsonPath('data.balance_after', '10.0000');

        $this->postJson('/api/ai-credit-transactions', [
            'institution_id' => $institution->id,
            'transaction_type' => 'consume',
            'credits' => 4,
            'source' => 'ai-service',
        ])
            ->assertCreated()
            ->assertJsonPath('data.credits', '-4.0000')
            ->assertJsonPath('data.balance_after', '6.0000');

        $this->postJson('/api/ai-credit-transactions', [
            'institution_id' => $institution->id,
            'transaction_type' => 'consume',
            'credits' => 7,
            'source' => 'ai-service',
        ])->assertUnprocessable();
    }

    public function test_institution_admin_can_only_read_own_subscription(): void
    {
        $firstInstitution = $this->createInstitution('SUB-A');
        $secondInstitution = $this->createInstitution('SUB-B');
        $plan = $this->createPlan('ENTERPRISE');
        $admin = $this->createInstitutionAdmin($firstInstitution);

        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'institution_id' => $firstInstitution->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'yearly',
            'starts_at' => now(),
        ]);
        $secondSubscription = Subscription::create([
            'uuid' => (string) Str::uuid(),
            'institution_id' => $secondInstitution->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'yearly',
            'starts_at' => now(),
        ]);

        $this->actingAs($admin, 'web');

        $this->getJson('/api/subscriptions')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.institution_id', $firstInstitution->id);

        $this->getJson('/api/subscriptions/'.$secondSubscription->id)
            ->assertForbidden();
    }

    private function createInstitution(string $code): Institution
    {
        return Institution::create([
            'name' => 'Institution '.$code,
            'code' => $code,
        ]);
    }

    private function createPlan(string $code): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => Str::headline(strtolower($code)),
            'code' => $code,
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

    private function createUserWithRole(string $roleName): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::firstOrCreate(
            [
                'name' => $roleName,
                'guard_name' => 'web',
            ],
            [
                'display_name' => Str::headline($roleName),
            ]
        );

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function createInstitutionAdmin(Institution $institution): User
    {
        $user = $this->createUserWithRole('institution-admin');

        InstitutionUser::create([
            'institution_id' => $institution->id,
            'user_id' => $user->id,
            'role_in_institution' => 'admin',
            'status' => 'active',
        ]);

        return $user;
    }
}
