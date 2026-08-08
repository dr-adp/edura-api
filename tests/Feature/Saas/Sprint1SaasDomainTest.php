<?php

namespace Tests\Feature\Saas;

use App\Models\Feature;
use App\Models\Institution;
use App\Models\InstitutionSetting;
use App\Models\InstitutionUser;
use App\Models\PlanFeature;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\FeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Sprint1SaasDomainTest extends TestCase
{
    use RefreshDatabase;

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
