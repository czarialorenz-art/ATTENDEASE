<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EnrollmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'class_id' => SchoolClass::factory(),
            'student_id' => User::factory(),
            'status' => Enrollment::PENDING,
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => Enrollment::APPROVED, 'decided_at' => now()]);
    }

    public function removed(): static
    {
        return $this->state(['status' => Enrollment::REMOVED, 'decided_at' => now()]);
    }
}