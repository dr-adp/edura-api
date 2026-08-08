<?php

namespace Database\Seeders;

use App\Models\Feature;
use Illuminate\Database\Seeder;

class FeatureSeeder extends Seeder
{
    public function run(): void
    {
        $features = [
            ['code' => 'live_classes', 'name' => 'Live Classes', 'category' => 'lms'],
            ['code' => 'recorded_classes', 'name' => 'Recorded Classes', 'category' => 'lms'],
            ['code' => 'ai_reports', 'name' => 'AI Reports', 'category' => 'ai'],
            ['code' => 'hand_sign_module', 'name' => 'Hand Sign Module', 'category' => 'accessibility'],
            ['code' => 'noticeboard', 'name' => 'Noticeboard', 'category' => 'communication'],
            ['code' => 'notes_upload', 'name' => 'Notes Upload', 'category' => 'lms'],
            ['code' => 'institution_branding', 'name' => 'Institution Branding', 'category' => 'saas'],
            ['code' => 'whatsapp_notifications', 'name' => 'WhatsApp Notifications', 'category' => 'communication'],
            ['code' => 'push_notifications', 'name' => 'Push Notifications', 'category' => 'communication'],
        ];

        foreach ($features as $feature) {
            Feature::updateOrCreate(
                ['code' => $feature['code']],
                array_merge($feature, [
                    'value_type' => 'boolean',
                    'default_value' => ['enabled' => false],
                    'status' => 'active',
                ])
            );
        }
    }
}
