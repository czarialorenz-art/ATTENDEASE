<?php

namespace Database\Factories;

use App\Models\AttendanceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ScanLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'attendance_record_id' => AttendanceRecord::factory(),
            'stage' => 'time_in',
            'ip' => fake()->ipv4(),
            'device_id' => (string) Str::uuid(),
            'latitude' => 14.6507,
            'longitude' => 121.0494,
            'accuracy' => 15,
            'distance_m' => 0,
            'scanned_at' => now(),
        ];
    }
}