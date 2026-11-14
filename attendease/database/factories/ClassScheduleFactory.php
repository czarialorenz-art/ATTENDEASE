<?php

namespace Database\Factories;

use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClassScheduleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'class_id' => SchoolClass::factory(),
            'day_of_week' => fake()->numberBetween(1, 5),
            'start_time' => '08:00',
            'end_time' => '09:30',
            'room' => 'Room '.fake()->numberBetween(100, 400),
        ];
    }
}