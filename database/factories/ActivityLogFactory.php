<?php

namespace Database\Factories;

use App\Models\ActivityLog;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $institution = Institution::query()->first()
            ?? Institution::create([
                'name' => fake()->company(),
                'code' => Str::upper(fake()->unique()->bothify('INST-####')),
            ]);

        return [
            'institution_id' => $institution->id,
            'user_id' => User::factory(),
            'module' => 'System',
            'action' => fake()->randomElement(['created', 'updated', 'deleted', 'viewed']),
            'description' => fake()->sentence(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'request_id' => (string) Str::uuid(),
            'auditable_type' => null,
            'auditable_id' => null,
            'model_type' => null,
            'model_id' => null,
            'old_values' => null,
            'new_values' => null,
            'metadata' => null,
            'properties' => null,
        ];
    }
}
