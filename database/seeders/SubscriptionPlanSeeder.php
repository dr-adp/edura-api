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
                'max_teachers' => 3,
                'max_students' => 100,
                'max_courses' => 10,
                'storage_limit_mb' => 2048,
                'allow_live_classes' => true,
                'allow_recorded_classes' => true,
                'allow_ai_reports' => false,
                'allow_hand_sign_module' => false,
                'allow_noticeboard' => true,
                'allow_notes_upload' => true,
                'description' => 'Best for individual tuition teachers and small coaching classes.',
                'status' => 'active',
            ],
            [
                'name' => 'Professional',
                'code' => 'PROFESSIONAL',
                'price' => 24999,
                'billing_cycle' => 'yearly',
                'max_teachers' => 15,
                'max_students' => 500,
                'max_courses' => 50,
                'storage_limit_mb' => 10240,
                'allow_live_classes' => true,
                'allow_recorded_classes' => true,
                'allow_ai_reports' => true,
                'allow_hand_sign_module' => true,
                'allow_noticeboard' => true,
                'allow_notes_upload' => true,
                'description' => 'Best for institutes that need LMS, AI reports, live classes, and accessibility tools.',
                'status' => 'active',
            ],
            [
                'name' => 'Enterprise',
                'code' => 'ENTERPRISE',
                'price' => 74999,
                'billing_cycle' => 'yearly',
                'max_teachers' => 100,
                'max_students' => 5000,
                'max_courses' => 500,
                'storage_limit_mb' => 102400,
                'allow_live_classes' => true,
                'allow_recorded_classes' => true,
                'allow_ai_reports' => true,
                'allow_hand_sign_module' => true,
                'allow_noticeboard' => true,
                'allow_notes_upload' => true,
                'description' => 'Best for large schools, colleges, universities, and training organizations.',
                'status' => 'active',
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
