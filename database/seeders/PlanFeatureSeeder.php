<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\PlanFeature;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class PlanFeatureSeeder extends Seeder
{
    public function run(): void
    {
        $matrix = [
            'STARTER' => [
                'live_classes',
                'recorded_classes',
                'noticeboard',
                'notes_upload',
            ],
            'PROFESSIONAL' => [
                'live_classes',
                'recorded_classes',
                'ai_reports',
                'hand_sign_module',
                'noticeboard',
                'notes_upload',
                'institution_branding',
            ],
            'ENTERPRISE' => [
                'live_classes',
                'recorded_classes',
                'ai_reports',
                'hand_sign_module',
                'noticeboard',
                'notes_upload',
                'institution_branding',
                'whatsapp_notifications',
                'push_notifications',
            ],
            'ENTERPRISE_PLUS' => [
                'live_classes',
                'recorded_classes',
                'ai_reports',
                'hand_sign_module',
                'noticeboard',
                'notes_upload',
                'institution_branding',
                'whatsapp_notifications',
                'push_notifications',
            ],
        ];

        foreach ($matrix as $planCode => $featureCodes) {
            $plan = SubscriptionPlan::where('code', $planCode)->first();

            if (!$plan) {
                continue;
            }

            foreach ($featureCodes as $featureCode) {
                $feature = Feature::where('code', $featureCode)->first();

                if (!$feature) {
                    continue;
                }

                PlanFeature::updateOrCreate(
                    [
                        'subscription_plan_id' => $plan->id,
                        'feature_id' => $feature->id,
                    ],
                    [
                        'enabled' => true,
                        'status' => 'active',
                    ]
                );
            }
        }
    }
}
