<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SchoolClassFactory extends Factory
{
    public function definition(): array
    {
        return [
            'teacher_id' => User::factory()->teacher(),
            'subject' => fake()->randomElement(['ITE101', 'ITE102', 'CS201', 'MATH101']),
            'year_level' => fake()->randomElement(['1st Year', '2nd Year', '3rd Year']),
            'section' => fake()->randomElement(['A', 'B', 'BSIT-1A', 'BSCS-2B']),
            'school_term' => '2026-2027',
            'semester' => '1st',
            'semester_start' => now()->subMonths(2)->toDateString(),
            'semester_end' => now()->addMonths(2)->toDateString(),
            'radius_m' => 100,
        ];
    }

    public function located(float $lat = 14.6507, float $lng = 121.0494, int $radius = 100): static
    {
        return $this->state(['latitude' => $lat, 'longitude' => $lng, 'radius_m' => $radius]);
    }
}