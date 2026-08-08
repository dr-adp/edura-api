<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter',
                'code' => 'STARTER',
                'price' => 9999,
                'billing_cycle' => 'yearly',
                'trial_days' => 14,
                'max_teachers' => 3,
                'max_students' => 100,
                'max_courses' => 10,
                'storage_limit_mb' => 2048,
                'included_ai_credits' => 0,
                'api_request_limit' => 10000,
                'limits' => [
                    'teachers' => 3,
                    'students' => 100,
                    'courses' => 10,
                    'storage_mb' => 2048,
                    'api_requests' => 10000,
                    'ai_credits' => 0,
                ],
                'allow_live_classes' => true,
                'allow_recorded_classes' => true,
                'allow_ai_reports' => false,
                'allow_hand_sign_module' => false,
                'allow_noticeboard' => true,
                'allow_notes_upload' => true,
                'description' => 'Best for individual tuition teachers and small coaching classes.',
                'status' => 'active',
                'sort_order' => 10,
            ],
            [
                'name' => 'Professional',
                'code' => 'PROFESSIONAL',
                'price' => 24999,
                'billing_cycle' => 'yearly',
                'trial_days' => 14,
                'max_teachers' => 15,
                'max_students' => 500,
                'max_courses' => 50,
                'storage_limit_mb' => 10240,
                'included_ai_credits' => 1000,
                'api_request_limit' => 50000,
                'limits' => [
                    'teachers' => 15,
                    'students' => 500,
                    'courses' => 50,
                    'storage_mb' => 10240,
                    'api_requests' => 50000,
                    'ai_credits' => 1000,
                ],
                'allow_live_classes' => true,
                'allow_recorded_classes' => true,
                'allow_ai_reports' => true,
                'allow_hand_sign_module' => true,
                'allow_noticeboard' => true,
                'allow_notes_upload' => true,
                'description' => 'Best for institutes that need LMS, AI reports, live classes, and accessibility tools.',
                'status' => 'active',
                'sort_order' => 20,
            ],
            [
                'name' => 'Enterprise',
                'code' => 'ENTERPRISE',
                'price' => 74999,
                'billing_cycle' => 'yearly',
                'trial_days' => 30,
                'max_teachers' => 100,
                'max_students' => 5000,
                'max_courses' => 500,
                'storage_limit_mb' => 102400,
                'included_ai_credits' => 10000,
                'api_request_limit' => 250000,
                'limits' => [
                    'teachers' => 100,
                    'students' => 5000,
                    'courses' => 500,
                    'storage_mb' => 102400,
                    'api_requests' => 250000,
                    'ai_credits' => 10000,
                ],
                'allow_live_classes' => true,
                'allow_recorded_classes' => true,
                'allow_ai_reports' => true,
                'allow_hand_sign_module' => true,
                'allow_noticeboard' => true,
                'allow_notes_upload' => true,
                'description' => 'Best for large schools, colleges, universities, and training organizations.',
                'status' => 'active',
                'sort_order' => 30,
            ],
            [
                'name' => 'Enterprise Plus',
                'code' => 'ENTERPRISE_PLUS',
                'price' => 149999,
                'billing_cycle' => 'yearly',
                'trial_days' => 30,
                'max_teachers' => 500,
                'max_students' => 25000,
                'max_courses' => 2500,
                'storage_limit_mb' => 512000,
                'included_ai_credits' => 50000,
                'api_request_limit' => 1000000,
                'limits' => [
                    'teachers' => 500,
                    'students' => 25000,
                    'courses' => 2500,
                    'storage_mb' => 512000,
                    'api_requests' => 1000000,
                    'ai_credits' => 50000,
                ],
                'allow_live_classes' => true,
                'allow_recorded_classes' => true,
                'allow_ai_reports' => true,
                'allow_hand_sign_module' => true,
                'allow_noticeboard' => true,
                'allow_notes_upload' => true,
                'description' => 'Best for large multi-campus institutions and enterprise training networks.',
                'status' => 'active',
                'sort_order' => 40,
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(
                ['code' => $plan['code']],
                $plan
            );
        }
    }
}
