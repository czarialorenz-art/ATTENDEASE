<?php

namespace Database\Factories;

use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttendanceSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'class_id' => SchoolClass::factory(),
            'date' => now()->toDateString(),
            'title' => null,
            'grace_minutes' => 15,
        ];
    }

    public function timeInOpen(): static
    {
        return $this->state(['time_in_opened_at' => now()]);
    }

    public function timeOutOpen(): static
    {
        return $this->state([
            'time_in_opened_at' => now()->subHour(),
            'time_in_closed_at' => now()->subMinutes(5),
            'time_out_opened_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state([
            'time_in_opened_at' => now()->subHours(2),
            'time_in_closed_at' => now()->subHours(1),
            'time_out_opened_at' => now()->subMinutes(30),
            'time_out_closed_at' => now()->subMinutes(10),
        ]);
    }
}